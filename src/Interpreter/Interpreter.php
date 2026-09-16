<?php

namespace GazLang\Interpreter;

use Exception;
use GazLang\AST\AbstractNodeVisitor;
use GazLang\AST\ArrayLiteralAST;
use GazLang\AST\AssignAST;
use GazLang\AST\BinOpAST;
use GazLang\AST\BooleanAST;
use GazLang\AST\CompoundAST;
use GazLang\AST\EchoStatementAST;
use GazLang\AST\FunctionCallAST;
use GazLang\AST\FunctionDeclarationAST;
use GazLang\AST\IfStatementAST;
use GazLang\AST\IndexAST;
use GazLang\AST\LoopControlAST;
use GazLang\AST\NullAST;
use GazLang\AST\NumAST;
use GazLang\AST\ReturnStatementAST;
use GazLang\AST\StatementAST;
use GazLang\AST\StringAST;
use GazLang\AST\UnaryOpAST;
use GazLang\AST\VariableAST;
use GazLang\AST\WhileStatementAST;
use GazLang\GazLangError;
use GazLang\Lexer\Token;
use GazLang\Parser\Parser;
use GazLang\Runtime\Builtins;
use GazLang\Runtime\Values;

/**
 * Interpreter class evaluates the AST
 *
 * This class runs the tree: scopes, function calls, control flow and writes through
 * array paths. What values mean (operators, truthiness, printing) lives in
 * Runtime\Values, and the builtin functions in Runtime\Builtins.
 */
class Interpreter extends AbstractNodeVisitor
{
    /**
     * Deepest allowed function call nesting, so runaway recursion is a GazLang error
     * instead of PHP running out of memory (a fatal error nothing can catch)
     */
    private const MAX_CALL_DEPTH = 10000;

    /**
     * @var Parser The parser that provides the AST
     */
    private $parser;

    /**
     * @var array Global (@name) variables, shared by the top level and every function
     */
    private $globals = [];

    /**
     * @var array Local ($name) variables of the running function call, or of the top level
     */
    private $locals = [];

    /**
     * @var array<string, FunctionDeclarationAST> Declared functions by name
     */
    private $functions = [];

    /**
     * @var Builtins The builtin functions, which hold the program's command line arguments
     */
    private $builtins;

    /**
     * @var int How many function calls are currently running
     */
    private $call_depth = 0;

    /**
     * @var ReturnSignal Reused for every return: creating an exception records a stack trace
     *                   (quadratic memory in deep recursion), rethrowing one does not
     */
    private $return_signal;

    /**
     * @var array<string, LoopSignal> Reused break and continue signals, keyed by token type, for the same reason
     */
    private $loop_signals;

    /**
     * Constructor
     *
     * @param  Parser  $parser  The parser to get the AST from
     * @param  string[]  $args  Command line arguments for the program, returned by args()
     */
    public function __construct(Parser $parser, array $args = [])
    {
        $this->parser = $parser;
        $this->builtins = new Builtins($args);
        $this->return_signal = new ReturnSignal;
        $this->loop_signals = [
            Token::BREAK => new LoopSignal(Token::BREAK),
            Token::CONTINUE => new LoopSignal(Token::CONTINUE),
        ];
    }

    /**
     * Visit a node, adding the node's file and line to errors raised while running it
     *
     * The innermost node with a location wins, since its visit sees the error first.
     *
     * @param  object  $node  The node to visit
     * @return mixed The result of visiting the node
     *
     * @throws GazLangError
     */
    public function visit(object $node)
    {
        try {
            return parent::visit($node);
        } catch (GazLangError|LoopSignal|ReturnSignal $e) {
            // Already located, or control flow rather than an error
            throw $e;
        } catch (Exception $e) {
            if ($node->line === null) {
                throw $e;
            }

            throw new GazLangError($e->getMessage(), $node->file, $node->line);
        }
    }

    /**
     * Visit a Variable node
     *
     * @param  VariableAST  $node  The node to visit
     * @return mixed The value of the variable
     *
     * @throws Exception If the variable is not defined
     */
    public function visitVariable(VariableAST $node)
    {
        $var_name = $node->value;
        $table = $node->isGlobal() ? $this->globals : $this->locals;
        // Not isset: a variable holding null is still defined
        if (! array_key_exists($var_name, $table)) {
            throw new Exception("Undefined variable: {$var_name}");
        }

        return $table[$var_name];
    }

    /**
     * Visit an Assign node
     *
     * @param  AssignAST  $node  The node to visit
     * @return mixed The value assigned to the variable
     */
    public function visitAssign(AssignAST $node)
    {
        if ($node->left instanceof VariableAST) {
            $value = $this->visit($node->right);
            if ($node->left->isGlobal()) {
                $this->globals[$node->left->value] = $value;
            } else {
                $this->locals[$node->left->value] = $value;
            }

            return $value;
        }

        // Keys are evaluated left to right before the value, and the path is only
        // walked afterwards, so the value expression can't invalidate it
        $indexes = [];
        for ($target = $node->left; $target instanceof IndexAST; $target = $target->target) {
            array_unshift($indexes, $target->index);
        }
        $keys = [];
        foreach ($indexes as $index) {
            $keys[] = $index === null ? null : Values::arrayKey($this->visit($index));
        }
        $value = $this->visit($node->right);

        // Arrays are values: writing in place through a PHP reference only changes this variable's copy
        $variable = $node->left->rootVariable();
        if ($variable->isGlobal()) {
            $container = &$this->globals;
        } else {
            $container = &$this->locals;
        }
        if (! array_key_exists($variable->value, $container)) {
            throw new Exception("Undefined variable: {$variable->value}");
        }
        $container = &$container[$variable->value];

        foreach ($keys as $i => $key) {
            if (! is_array($container)) {
                throw new Exception('Cannot use [] on '.get_debug_type($container));
            }
            if ($key === null) {
                $container[] = $value;

                return $value;
            }
            // Only the last key may be new; missing keys along the way are not created
            if ($i < count($keys) - 1 && ! array_key_exists($key, $container)) {
                throw new Exception("Undefined key: {$key}");
            }
            $container = &$container[$key];
        }
        $container = $value;

        return $value;
    }

    /**
     * Visit a BinOp node
     *
     * @param  BinOpAST  $node  The node to visit
     * @return int|string|bool The result of the binary operation
     */
    public function visitBinOp(BinOpAST $node)
    {
        // Logical operators short-circuit, so the right side is only evaluated when needed
        if ($node->op->type === Token::AND) {
            return Values::isTruthy($this->visit($node->left)) && Values::isTruthy($this->visit($node->right));
        } elseif ($node->op->type === Token::OR) {
            return Values::isTruthy($this->visit($node->left)) || Values::isTruthy($this->visit($node->right));
        }

        return Values::binary($node->op, $this->visit($node->left), $this->visit($node->right));
    }

    /**
     * Visit a UnaryOp node
     *
     * @param  UnaryOpAST  $node  The node to visit
     * @return int|bool The result of the unary operation
     */
    public function visitUnaryOp(UnaryOpAST $node): int|bool
    {
        $value = $this->visit($node->expr);

        if ($node->op->type === Token::NOT) {
            return ! Values::isTruthy($value);
        } elseif ($node->op->type === Token::MINUS) {
            return Values::negate($value);
        }

        throw new Exception("Unknown operator: {$node->op->type}");
    }

    /**
     * Visit a Num node
     *
     * @param  NumAST  $node  The node to visit
     * @return int The numeric value
     */
    public function visitNum(NumAST $node): int
    {
        return $node->value;
    }

    /**
     * Visit a Boolean node
     *
     * @param  BooleanAST  $node  The node to visit
     * @return bool The boolean value
     */
    public function visitBoolean(BooleanAST $node): bool
    {
        return $node->value;
    }

    /**
     * Visit a Null node
     *
     * @param  NullAST  $node  The node to visit
     */
    public function visitNull(NullAST $node): null
    {
        return null;
    }

    /**
     * Visit a String node
     *
     * @param  StringAST  $node  The node to visit
     * @return string The string value
     */
    public function visitString(StringAST $node): string
    {
        return $node->value;
    }

    /**
     * Visit a Statement node
     *
     * @param  StatementAST  $node  The node to visit
     * @return mixed The result of the statement
     */
    public function visitStatement(StatementAST $node)
    {
        // Evaluate the expression but don't output it
        return $this->visit($node->expr);
    }

    /**
     * Visit an EchoStatement node
     *
     * @param  EchoStatementAST  $node  The node to visit
     * @return mixed The result of the echo statement
     */
    public function visitEchoStatement(EchoStatementAST $node)
    {
        $result = $this->visit($node->expr);
        // Print the result directly to the terminal
        echo Values::toString($result).PHP_EOL;

        return $result;
    }

    /**
     * Visit a Compound node
     *
     * @param  CompoundAST  $node  The node to visit
     * @return null Statements produce no result; keeping theirs would hold extra references
     *              to arrays and make the next in-place write copy them
     */
    public function visitCompound(CompoundAST $node): null
    {
        foreach ($node->statements as $statement) {
            $this->visit($statement);
        }

        return null;
    }

    /**
     * Visit an IfStatement node
     *
     * @param  IfStatementAST  $node  The node to visit
     * @return null Statements produce no result
     */
    public function visitIfStatement(IfStatementAST $node): null
    {
        if (Values::isTruthy($this->visit($node->condition))) {
            // Execute the if branch
            $this->visit($node->if_body);
        } elseif ($node->else_if !== null) {
            // Execute the else-if branch if it exists
            $this->visit($node->else_if);
        } elseif ($node->else_body !== null) {
            // Execute the else branch if it exists
            $this->visit($node->else_body);
        }

        return null;
    }

    /**
     * Visit a WhileStatement node
     *
     * @param  WhileStatementAST  $node  The node to visit
     * @return null Loops produce no result
     */
    public function visitWhileStatement(WhileStatementAST $node): null
    {
        while (Values::isTruthy($this->visit($node->condition))) {
            try {
                $this->visit($node->body);
            } catch (LoopSignal $signal) {
                if ($signal->type === Token::BREAK) {
                    break;
                }
            }

            if ($node->step !== null) {
                $this->visit($node->step);
            }
        }

        return null;
    }

    /**
     * Visit a LoopControl node by unwinding to the innermost loop
     *
     * @param  LoopControlAST  $node  The node to visit
     *
     * @throws LoopSignal Always, caught by visitWhileStatement
     */
    public function visitLoopControl(LoopControlAST $node): never
    {
        throw $this->loop_signals[$node->token->type];
    }

    /**
     * Visit an ArrayLiteral node
     *
     * @param  ArrayLiteralAST  $node  The node to visit
     */
    public function visitArrayLiteral(ArrayLiteralAST $node): array
    {
        $array = [];
        foreach ($node->entries as [$key, $value]) {
            if ($key === null) {
                $array[] = $this->visit($value);
            } else {
                // Duplicate keys: the last one wins
                $array[Values::arrayKey($this->visit($key))] = $this->visit($value);
            }
        }

        return $array;
    }

    /**
     * Visit an Index node (reading only; writes go through visitAssign)
     *
     * @param  IndexAST  $node  The node to visit
     * @return mixed The element, a one character string, or null if the key or position does not exist
     */
    public function visitIndex(IndexAST $node)
    {
        return Values::index($this->visit($node->target), $this->visit($node->index));
    }

    /**
     * Visit a FunctionDeclaration node; declarations are registered up front by interpret()
     *
     * @param  FunctionDeclarationAST  $node  The node to visit
     * @return null Declarations produce no result
     */
    public function visitFunctionDeclaration(FunctionDeclarationAST $node): null
    {
        return null;
    }

    /**
     * Visit a FunctionCall node, running the body with its own locals
     *
     * The parser has already checked the function exists and the argument count matches.
     *
     * @param  FunctionCallAST  $node  The node to visit
     * @return mixed The returned value, or null if the function did not return one
     */
    public function visitFunctionCall(FunctionCallAST $node)
    {
        // Arguments are evaluated in the caller's scope, before switching locals
        $args = array_map(fn ($arg) => $this->visit($arg), $node->args);

        if (isset(Builtins::ARITIES[$node->name])) {
            return $this->builtins->call($node->name, $args);
        }

        $function = $this->functions[$node->name];

        if ($this->call_depth === self::MAX_CALL_DEPTH) {
            throw new Exception('Maximum call depth of '.self::MAX_CALL_DEPTH." exceeded calling {$node->name}");
        }

        $caller_locals = $this->locals;
        $this->locals = array_combine($function->params, $args);
        $this->call_depth++;

        try {
            $this->visit($function->body);

            return null;
        } catch (ReturnSignal $signal) {
            // The signal is reused, so let go of the value: holding a second reference
            // to a returned array would make the next write to it copy the whole array
            $value = $signal->value;
            $signal->value = null;

            return $value;
        } finally {
            $this->locals = $caller_locals;
            $this->call_depth--;
        }
    }

    /**
     * Visit a ReturnStatement node by unwinding to the function call
     *
     * @param  ReturnStatementAST  $node  The node to visit
     *
     * @throws ReturnSignal Always, caught by visitFunctionCall
     */
    public function visitReturnStatement(ReturnStatementAST $node): never
    {
        $this->return_signal->value = $node->expr === null ? null : $this->visit($node->expr);

        throw $this->return_signal;
    }

    /**
     * Interpret the AST
     *
     * @return void No return value as echo statements handle their own output
     */
    public function interpret(): void
    {
        $tree = $this->parser->parse();

        // Register every function first, so calls can come before declarations
        foreach ($tree->statements as $statement) {
            if ($statement instanceof FunctionDeclarationAST) {
                $this->functions[$statement->name] = $statement;
            }
        }

        $this->visit($tree);
    }
}
