<?php

namespace GazLang\Interpreter;

use Exception;
use GazLang\AST\AbstractNodeVisitor;
use GazLang\AST\ArrayLiteralAST;
use GazLang\AST\AssignAST;
use GazLang\AST\AST;
use GazLang\AST\BinOpAST;
use GazLang\AST\BooleanAST;
use GazLang\AST\CallValueAST;
use GazLang\AST\CompoundAST;
use GazLang\AST\EchoStatementAST;
use GazLang\AST\ForeachStatementAST;
use GazLang\AST\FunctionCallAST;
use GazLang\AST\FunctionDeclarationAST;
use GazLang\AST\FunctionRefAST;
use GazLang\AST\IfStatementAST;
use GazLang\AST\IncrementAST;
use GazLang\AST\IndexAST;
use GazLang\AST\LoopControlAST;
use GazLang\AST\NullAST;
use GazLang\AST\NumAST;
use GazLang\AST\ReturnStatementAST;
use GazLang\AST\StatementAST;
use GazLang\AST\StringAST;
use GazLang\AST\TernaryAST;
use GazLang\AST\TryStatementAST;
use GazLang\AST\UnaryOpAST;
use GazLang\AST\VariableAST;
use GazLang\AST\WhileStatementAST;
use GazLang\GazLangError;
use GazLang\Lexer\Token;
use GazLang\Parser\Parser;
use GazLang\Runtime\Builtins;
use GazLang\Runtime\FunctionValue;
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
        } catch (LoopSignal|ReturnSignal $e) {
            // Control flow rather than an error
            throw $e;
        } catch (GazLangError $e) {
            // Already located, or raised without a location (by error()): add this node's
            if ($e->line_number !== null || $node->line === null) {
                throw $e;
            }

            throw new GazLangError($e->reason, $node->file, $node->line, $e->show_location);
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
     * Visit an Assign node: =, or a compound assignment like +=
     *
     * @param  AssignAST  $node  The node to visit
     * @return mixed The value assigned
     */
    public function visitAssign(AssignAST $node)
    {
        if ($node->left instanceof VariableAST && $node->token->type === Token::ASSIGN) {
            $value = $this->visit($node->right);
            $this->assignVariable($node->left, $value);

            return $value;
        }

        // Keys are evaluated left to right, then the value, and only then is the target
        // read and written, so the value expression can't invalidate the path
        $keys = $this->evaluateKeys($node->left);

        // $a ??= $b is $a ?? ($a = $b): the right side only runs when the target is null or missing
        if ($node->token->type === Token::COALESCE_ASSIGN) {
            $variable = $node->left instanceof VariableAST ? $node->left : $node->left->rootVariable();
            $current = ($variable->isGlobal() ? $this->globals : $this->locals)[$variable->value] ?? null;
            foreach ($keys as $key) {
                $current = $current === null ? null : Values::index($current, $key);
            }
            if ($current !== null) {
                return $current;
            }
        }

        $value = $this->visit($node->right);
        // A compound assignment applies the operator its token is named after (PLUS_ASSIGN, +=), as the code generator does
        $token = $node->token;
        $operator = $token->type === Token::ASSIGN || $token->type === Token::COALESCE_ASSIGN
            ? null
            : new Token(str_replace('_ASSIGN', '', $token->type), substr($token->value, 0, -1));
        [, $new] = $this->store($node->left, $keys, $operator, $value);

        return $new;
    }

    /**
     * Visit an Increment node (++ or --)
     *
     * @param  IncrementAST  $node  The node to visit
     * @return int|float The new value for ++$x, the old value for $x++
     */
    public function visitIncrement(IncrementAST $node): int|float
    {
        [$old, $new] = $this->store($node->target, $this->evaluateKeys($node->target), $node->op, null);

        return $node->prefix ? $new : $old;
    }

    /**
     * Evaluate the index keys of an assignment target, left to right
     *
     * @param  VariableAST|IndexAST  $target  The target
     * @return array The keys, outermost first; null for an append ([])
     */
    private function evaluateKeys(VariableAST|IndexAST $target): array
    {
        $indexes = [];
        for (; $target instanceof IndexAST; $target = $target->target) {
            array_unshift($indexes, $target->index);
        }

        $keys = [];
        foreach ($indexes as $index) {
            $keys[] = $index === null ? null : Values::arrayKey($this->visit($index));
        }

        return $keys;
    }

    /**
     * Write to a variable or an element of one, given its evaluated keys (see Values::store())
     *
     * @param  VariableAST|IndexAST  $target  The target
     * @param  array  $keys  Its evaluated keys, from evaluateKeys()
     * @param  Token|null  $op  How to combine with the current value, or null to replace it
     * @param  mixed  $value  The right hand side (unused for ++ and --)
     * @return array{0: mixed, 1: mixed} The old value (null if there was none) and the new value
     *
     * @throws Exception If a variable or key along the way is missing, or the operation fails
     */
    private function store(VariableAST|IndexAST $target, array $keys, ?Token $op, $value): array
    {
        $variable = $target instanceof VariableAST ? $target : $target->rootVariable();
        if ($variable->isGlobal()) {
            return Values::store($this->globals, $variable->value, $variable->value, $keys, $op, $value);
        }

        return Values::store($this->locals, $variable->value, $variable->value, $keys, $op, $value);
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
        } elseif ($node->op->type === Token::COALESCE) {
            return $this->quietly($node->left) ?? $this->visit($node->right);
        }

        return Values::binary($node->op, $this->visit($node->left), $this->visit($node->right));
    }

    /**
     * Evaluate the left side of ??, where a missing variable or key is null rather than an error
     *
     * Only what "missing" means is quiet: an undefined variable, a missing key, or indexing
     * something already missing (null). Index expressions still run, and any other error,
     * like a bad key type or indexing an int, is still raised.
     *
     * @param  AST  $node  The left side
     * @return mixed The value, or null if it is missing
     */
    private function quietly(AST $node)
    {
        if ($node instanceof VariableAST) {
            return ($node->isGlobal() ? $this->globals : $this->locals)[$node->value] ?? null;
        }
        if ($node instanceof IndexAST && $node->index !== null) {
            $target = $this->quietly($node->target);
            $index = $this->visit($node->index);

            return $target === null ? null : Values::index($target, $index);
        }

        return $this->visit($node);
    }

    /**
     * Visit a UnaryOp node
     *
     * @param  UnaryOpAST  $node  The node to visit
     * @return int|float|bool The result of the unary operation
     */
    public function visitUnaryOp(UnaryOpAST $node): int|float|bool
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
     * @return int|float The numeric value
     */
    public function visitNum(NumAST $node): int|float
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
     * Visit a ForeachStatement node
     *
     * The array is evaluated once and iterated as it was then: arrays are values, so
     * changing the variable inside the loop doesn't change what is iterated.
     *
     * @param  ForeachStatementAST  $node  The node to visit
     * @return null Loops produce no result
     *
     * @throws Exception If the expression is not an array
     */
    public function visitForeachStatement(ForeachStatementAST $node): null
    {
        $array = $this->visit($node->iterable);
        if (! is_array($array)) {
            throw new Exception('foreach expects an array, got '.Values::typeOf($array));
        }

        foreach ($array as $key => $value) {
            if ($node->key !== null) {
                $this->assignVariable($node->key, $key);
            }
            $this->assignVariable($node->value, $value);

            try {
                $this->visit($node->body);
            } catch (LoopSignal $signal) {
                if ($signal->type === Token::BREAK) {
                    break;
                }
            }
        }

        return null;
    }

    /**
     * Assign a value to a local or global variable
     *
     * @param  VariableAST  $variable  The variable
     * @param  mixed  $value  The value
     */
    private function assignVariable(VariableAST $variable, $value): void
    {
        if ($variable->isGlobal()) {
            $this->globals[$variable->value] = $value;
        } else {
            $this->locals[$variable->value] = $value;
        }
    }

    /**
     * Visit a TryStatement node
     *
     * Any runtime error in the body (a GazLangError, which every error becomes once it
     * passes a node with a location) is caught and assigned to the catch variable as
     * ["message" => ..., "file" => ..., "line" => ...]. return, break and continue are
     * not errors and pass straight through.
     *
     * @param  TryStatementAST  $node  The node to visit
     * @return null Statements produce no result
     */
    public function visitTryStatement(TryStatementAST $node): null
    {
        try {
            $this->visit($node->body);
        } catch (GazLangError $error) {
            $this->assignVariable($node->variable, [
                'message' => $error->reason,
                'file' => $error->path,
                'line' => $error->line_number,
            ]);
            $this->visit($node->catch_body);
        }

        return null;
    }

    /**
     * Visit a LoopControl node by unwinding to the innermost loop
     *
     * @param  LoopControlAST  $node  The node to visit
     *
     * @throws LoopSignal Always, caught by the innermost visitWhileStatement or visitForeachStatement
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
        $target = $this->visit($node->target);
        $index = $this->visit($node->index);

        return $node->existing ? Values::indexExisting($target, $index) : Values::index($target, $index);
    }

    /**
     * Visit a Ternary node, evaluating only the taken branch
     *
     * @param  TernaryAST  $node  The node to visit
     * @return mixed The value of the taken branch
     */
    public function visitTernary(TernaryAST $node)
    {
        return Values::isTruthy($this->visit($node->condition)) ? $this->visit($node->then) : $this->visit($node->else);
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
     * Visit a FunctionRef node
     *
     * @param  FunctionRefAST  $node  The node to visit
     * @return FunctionValue The function as a value
     */
    public function visitFunctionRef(FunctionRefAST $node): FunctionValue
    {
        return FunctionValue::named($node->name);
    }

    /**
     * Visit a FunctionCall node, a call by name
     *
     * The parser has already checked the function exists and the argument count matches.
     *
     * @param  FunctionCallAST  $node  The node to visit
     * @return mixed The returned value, or null if the function did not return one
     */
    public function visitFunctionCall(FunctionCallAST $node)
    {
        // Arguments are evaluated in the caller's scope, before switching locals
        return $this->call($node->name, array_map(fn ($arg) => $this->visit($arg), $node->args));
    }

    /**
     * Visit a CallValue node, a call on whatever an expression evaluates to
     *
     * The callee is evaluated, then the arguments, and only then is it checked to be a
     * function that takes that many arguments; the code generator emits the same order.
     *
     * @param  CallValueAST  $node  The node to visit
     * @return mixed The returned value
     *
     * @throws Exception If the callee is not a function or the argument count is wrong
     */
    public function visitCallValue(CallValueAST $node)
    {
        $callee = $this->visit($node->callee);
        $args = array_map(fn ($arg) => $this->visit($arg), $node->args);

        if (! $callee instanceof FunctionValue) {
            throw new Exception('Cannot call '.Values::typeOf($callee));
        }
        $arity = Builtins::ARITIES[$callee->name] ?? $this->functions[$callee->name]->arity;
        $error = Builtins::arityError($callee->name, $arity, count($args));
        if ($error !== null) {
            throw new Exception($error);
        }

        return $this->call($callee->name, $args);
    }

    /**
     * Call a builtin or user function with evaluated arguments, running a user function's body with its own locals
     *
     * @param  string  $name  The function name, already checked to exist and to take this many arguments
     * @param  array  $args  The evaluated arguments
     * @return mixed The returned value, or null if the function did not return one
     */
    private function call(string $name, array $args)
    {
        if (isset(Builtins::ARITIES[$name])) {
            return $this->builtins->call($name, $args);
        }

        $function = $this->functions[$name];

        if ($this->call_depth === Values::MAX_CALL_DEPTH) {
            throw new Exception('Maximum call depth of '.Values::MAX_CALL_DEPTH." exceeded calling {$name}");
        }

        $caller_locals = $this->locals;
        $this->locals = array_combine(array_slice($function->params, 0, count($args)), $args);
        $this->call_depth++;

        try {
            // Defaults are evaluated on every call that leaves them out, inside the function, so
            // they can use earlier parameters and never share a value between calls
            for ($i = count($args); $i < count($function->params); $i++) {
                $this->locals[$function->params[$i]] = $this->visit($function->defaults[$i]);
            }

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
