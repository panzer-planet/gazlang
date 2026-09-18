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
use GazLang\AST\ClassDeclarationAST;
use GazLang\AST\CompoundAST;
use GazLang\AST\DeleteStatementAST;
use GazLang\AST\EchoStatementAST;
use GazLang\AST\ForeachStatementAST;
use GazLang\AST\FunctionCallAST;
use GazLang\AST\FunctionDeclarationAST;
use GazLang\AST\FunctionRefAST;
use GazLang\AST\IfStatementAST;
use GazLang\AST\IncrementAST;
use GazLang\AST\IndexAST;
use GazLang\AST\LambdaAST;
use GazLang\AST\ListPatternAST;
use GazLang\AST\LoopControlAST;
use GazLang\AST\MatchAST;
use GazLang\AST\MethodCallAST;
use GazLang\AST\NullAST;
use GazLang\AST\NumAST;
use GazLang\AST\ParentMethodAST;
use GazLang\AST\PropertyAST;
use GazLang\AST\ReturnStatementAST;
use GazLang\AST\StatementAST;
use GazLang\AST\StringAST;
use GazLang\AST\TernaryAST;
use GazLang\AST\ThisAST;
use GazLang\AST\TryStatementAST;
use GazLang\AST\UnaryOpAST;
use GazLang\AST\VariableAST;
use GazLang\AST\WhileStatementAST;
use GazLang\GazLangError;
use GazLang\Lexer\Token;
use GazLang\Parser\Parser;
use GazLang\Runtime\Builtins;
use GazLang\Runtime\ClassValue;
use GazLang\Runtime\ExitSignal;
use GazLang\Runtime\FunctionValue;
use GazLang\Runtime\MapValue;
use GazLang\Runtime\ObjectValue;
use GazLang\Runtime\PropertyStep;
use GazLang\Runtime\Values;
use Throwable;

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
     * @var FunctionValue|null The closure whose call is running, whose captured variables its body
     *                         reads and writes in place of locals; null outside a closure
     */
    private $closure = null;

    /**
     * @var array<string, int> The running closure's captured variable names, as keys
     */
    private $captures = [];

    /**
     * @var ObjectValue|null The object # is in the running method (or the closure made in one); null elsewhere
     */
    private $receiver = null;

    /**
     * @var array<string, FunctionDeclarationAST> Declared functions by name
     */
    private $functions = [];

    /**
     * @var array<string, ClassDeclarationAST> The declaration of each class, which holds the field defaults and method bodies
     */
    private $declarations = [];

    /**
     * @var array<string, ClassValue> Declared classes by name
     */
    private $classes = [];

    /**
     * @var Builtins The builtin functions, which hold the program's command line arguments
     */
    private $builtins;

    /**
     * @var int How many function calls are currently running
     */
    private $call_depth = 0;

    /**
     * @var list<array{0: string, 1: string|null, 2: int|null}> The calls running, outermost first, each as
     *                                                          [what it is, and the file and line it was called from].
     *                                                          A call with no location is a to_string() run by printing,
     *                                                          which starts a trace of its own, as it does in the VM
     */
    private $calls = [];

    /**
     * @var array{0: string|null, 1: int|null}|null The location of the call being made, which the frame it
     *                                              starts records; null for a method run by printing
     */
    private $call_site = null;

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
        } catch (LoopSignal|ReturnSignal|ExitSignal $e) {
            // Control flow rather than an error
            throw $e;
        } catch (GazLangError $e) {
            // Already located, or raised without a location (by error()): add this node's
            if ($e->line_number !== null || $node->line === null) {
                throw $e;
            }
            $e->trace ??= $this->trace($node->file, $node->line);

            throw $e->located($node->file, $node->line);
        } catch (Exception $e) {
            if ($node->line === null) {
                throw $e;
            }
            $error = new GazLangError($e->getMessage(), $node->file, $node->line);
            $error->trace = $this->trace($node->file, $node->line);

            throw $error;
        }
    }

    /**
     * The calls running, innermost first, for an error raised at a location
     *
     * Each call is shown where it was running: the innermost where the error happened, the
     * ones around it where they made the call below. A method run by printing an object
     * starts a trace of its own, since the VM runs it in a loop of its own.
     *
     * @param  string|null  $file  The file the error happened in
     * @param  int  $line  The line it happened on
     * @return list<string> The trace
     */
    private function trace(?string $file, int $line): array
    {
        $frames = [];
        $at = [$file, $line];
        foreach (array_reverse($this->calls) as [$what, $call_file, $call_line]) {
            $frames[] = [$what, ...$at];
            if ($call_line === null) {
                return GazLangError::trace($frames);
            }
            $at = [$call_file, $call_line];
        }
        $frames[] = ['top level', ...$at];

        return GazLangError::trace($frames);
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
        // variables(), inlined: this is the interpreter's hottest path
        if ($node->isGlobal()) {
            $table = $this->globals;
        } elseif (isset($this->captures[$var_name])) {
            $table = $this->closure->captured;
        } else {
            $table = $this->locals;
        }
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
        if ($node->left instanceof ListPatternAST) {
            $value = $this->visit($node->right);
            $this->destructure($node->left, $value);

            return $value;
        }
        if ($node->left instanceof PropertyAST && $node->left->target instanceof ThisAST && $node->token->type === Token::ASSIGN) {
            // #name = value, a field the parser has checked the class declares
            $value = $this->visit($node->right);
            $this->receiver->fields[$node->left->name] = $value;

            return $value;
        }

        // Keys are evaluated left to right, then the value, and only then is the target
        // read and written, so the value expression can't invalidate the path
        $keys = $this->evaluateKeys($node->left);

        // $a ??= $b is $a ?? ($a = $b): the right side only runs when the target is null or missing
        if ($node->token->type === Token::COALESCE_ASSIGN) {
            $root = AST::pathRoot($node->left);
            $current = $root instanceof ThisAST ? $this->receiver : $this->variables($root)[$root->value] ?? null;
            foreach ($keys as $key) {
                $current = match (true) {
                    $current === null => null,
                    $key instanceof PropertyStep => Values::property($current, $key->name, true),
                    default => Values::index($current, $key, true),
                };
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
     * Take a list apart into a pattern's targets: the value is checked first, then each target's keys are evaluated and it is written, left to right
     *
     * @param  ListPatternAST  $pattern  The pattern
     * @param  mixed  $value  The value, which must be a list of as many elements as there are targets
     */
    private function destructure(ListPatternAST $pattern, $value): void
    {
        Values::destructure($value, count($pattern->targets));
        foreach ($pattern->targets as $i => $target) {
            $this->store($target, $this->evaluateKeys($target), null, $value[$i]);
        }
    }

    /**
     * Evaluate the steps of an assignment target's path, left to right
     *
     * @param  VariableAST|IndexAST|PropertyAST  $target  The target
     * @return array The steps, outermost first: each index's key, null for an append ([]), or a PropertyStep
     */
    private function evaluateKeys(VariableAST|IndexAST|PropertyAST $target): array
    {
        $steps = [];
        for (; $target instanceof IndexAST || $target instanceof PropertyAST; $target = $target->target) {
            array_unshift($steps, $target);
        }

        $keys = [];
        foreach ($steps as $step) {
            $keys[] = match (true) {
                $step instanceof PropertyAST => new PropertyStep($step->name),
                $step->index === null => null,
                default => Values::arrayKey($this->visit($step->index)),
            };
        }

        return $keys;
    }

    /**
     * Write to a variable or an element of one, given its evaluated keys (see Values::store())
     *
     * @param  VariableAST|IndexAST|PropertyAST  $target  The target
     * @param  array  $keys  Its evaluated steps, from evaluateKeys()
     * @param  Token|null  $op  How to combine with the current value, or null to replace it
     * @param  mixed  $value  The right hand side (unused for ++ and --)
     * @return array{0: mixed, 1: mixed} The old value (null if there was none) and the new value
     *
     * @throws Exception If a variable or key along the way is missing, or the operation fails
     */
    private function store(VariableAST|IndexAST|PropertyAST $target, array $keys, ?Token $op, $value): array
    {
        $variable = AST::pathRoot($target);
        if ($variable instanceof ThisAST) {
            // The object is a handle, so writing through a table holding it writes the object
            $table = ['#' => $this->receiver];

            return Values::store($table, '#', '#', $keys, $op, $value);
        }
        if ($variable->isGlobal()) {
            return Values::store($this->globals, $variable->value, $variable->value, $keys, $op, $value);
        }
        if (isset($this->captures[$variable->value])) {
            return Values::store($this->closure->captured, $variable->value, $variable->value, $keys, $op, $value);
        }

        return Values::store($this->locals, $variable->value, $variable->value, $keys, $op, $value);
    }

    /**
     * Visit a Delete node: remove an element of a list or map, through its variable
     *
     * The keys are evaluated left to right, then the variable is read and written, as an
     * assignment does it.
     *
     * @param  DeleteStatementAST  $node  The node to visit
     * @return null A statement has no value
     */
    public function visitDeleteStatement(DeleteStatementAST $node): null
    {
        $keys = $this->evaluateKeys($node->target);
        $variable = AST::pathRoot($node->target);

        if ($variable instanceof ThisAST) {
            // The object is a handle, so removing through a table holding it writes the object
            $table = ['#' => $this->receiver];
            Values::remove($table, '#', '#', $keys);
        } elseif ($variable->isGlobal()) {
            Values::remove($this->globals, $variable->value, $variable->value, $keys);
        } elseif (isset($this->captures[$variable->value])) {
            Values::remove($this->closure->captured, $variable->value, $variable->value, $keys);
        } else {
            Values::remove($this->locals, $variable->value, $variable->value, $keys);
        }

        return null;
    }

    /**
     * The variables a variable node reads from: globals, the running closure's captured variables, or locals
     *
     * @param  VariableAST  $variable  The variable
     */
    private function variables(VariableAST $variable): array
    {
        return match (true) {
            $variable->isGlobal() => $this->globals,
            isset($this->captures[$variable->value]) => $this->closure->captured,
            default => $this->locals,
        };
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
            return $this->variables($node)[$node->value] ?? null;
        }
        if ($node instanceof IndexAST && $node->index !== null) {
            $target = $this->quietly($node->target);
            $index = $this->visit($node->index);

            return $target === null ? null : Values::index($target, $index, true);
        }
        if ($node instanceof PropertyAST) {
            $target = $this->quietly($node->target);

            return $target === null ? null : Values::property($target, $node->name, true);
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
        } elseif ($node->op->type === Token::BIT_NOT) {
            return Values::bitwiseNot($value);
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
     * The list or map is evaluated once and iterated as it was then: they are values, so
     * changing the variable inside the loop doesn't change what is iterated.
     *
     * @param  ForeachStatementAST  $node  The node to visit
     * @return null Loops produce no result
     *
     * @throws Exception If the expression is not a list or map
     */
    public function visitForeachStatement(ForeachStatementAST $node): null
    {
        $iterable = $this->visit($node->iterable);
        if (! is_array($iterable) && ! $iterable instanceof MapValue) {
            throw new Exception('foreach expects a list or map, got '.Values::typeOf($iterable));
        }

        $map = $iterable instanceof MapValue;
        foreach ($map ? $iterable->items : $iterable as $key => $value) {
            if ($node->key !== null) {
                $this->assignVariable($node->key, $map ? MapValue::unkey($key) : $key);
            }
            if ($node->value instanceof ListPatternAST) {
                $this->destructure($node->value, $value);
            } else {
                $this->assignVariable($node->value, $value);
            }

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
     * passes a node with a location) can be caught: a runtime error or error("text") as an
     * Error object with its message, file and line, any other value thrown with error() as
     * it is. return, break and continue are not errors and pass straight through.
     *
     * The finally block runs however the try and catch blocks are left: at their end, by an
     * error (caught or not), or by return, break or continue, which then carry on. A value
     * being returned is kept while it runs. exit() stops the program without running it.
     *
     * @param  TryStatementAST  $node  The node to visit
     * @return null Statements produce no result
     */
    public function visitTryStatement(TryStatementAST $node): null
    {
        if ($node->finally === null) {
            $this->tryCatch($node);

            return null;
        }

        try {
            $this->tryCatch($node);
        } catch (ExitSignal $exit) {
            throw $exit;
        } catch (ReturnSignal $signal) {
            // The signal is reused, so a return inside the finally block would overwrite the value
            $value = $signal->value;
            $this->visit($node->finally);
            $signal->value = $value;

            throw $signal;
        } catch (Throwable $e) {
            $this->visit($node->finally);

            throw $e;
        }
        $this->visit($node->finally);

        return null;
    }

    /**
     * Run a try block and the first catch clause that matches an error, if any
     *
     * A clause with a class matches an object of that class or a subclass; one without
     * matches anything. An error no clause matches carries on as it was.
     *
     * @param  TryStatementAST  $node  The try statement
     */
    private function tryCatch(TryStatementAST $node): void
    {
        try {
            $this->visit($node->body);
        } catch (GazLangError $error) {
            if ($node->catches === []) {
                throw $error;
            }
            $value = $error->caught($this->classes['Error']);
            foreach ($node->catches as [$class, $variable, $body]) {
                if ($class === null || ($value instanceof ObjectValue && $value->class->isA($this->classes[$class]))) {
                    $this->assignVariable($variable, $value);
                    $this->visit($body);

                    return;
                }
            }

            throw $error;
        }
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
     * @return array|MapValue A list, or a map
     */
    public function visitArrayLiteral(ArrayLiteralAST $node): array|MapValue
    {
        if (! $node->map) {
            $list = [];
            foreach ($node->entries as [, $value]) {
                $list[] = $this->visit($value);
            }

            return $list;
        }

        $map = new MapValue;
        foreach ($node->entries as [$key, $value]) {
            // Duplicate keys: the last one wins
            $map->items[MapValue::key(Values::arrayKey($this->visit($key)))] = $this->visit($value);
        }

        return $map;
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
     * Visit a Match node: the subject once, then each arm's values in order until one is equal
     *
     * Without a subject the values are conditions, tested for truth instead of compared.
     *
     * @param  MatchAST  $node  The node to visit
     * @return mixed The matching arm's value; null for a block arm
     *
     * @throws Exception If no arm matches and there is no default
     */
    public function visitMatch(MatchAST $node)
    {
        $subject = $node->subject === null ? null : $this->visit($node->subject);

        foreach ($node->arms as [$values, $body]) {
            if ($values === null) {
                return $this->visit($body);
            }
            foreach ($values as $value) {
                $matched = $node->subject === null
                    ? Values::isTruthy($this->visit($value))
                    : Values::equals($subject, $this->visit($value));
                if ($matched) {
                    return $this->visit($body);
                }
            }
        }

        throw $node->subject === null ? Values::noCondition() : Values::noMatch($subject);
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
     * Visit a ClassDeclaration node; classes are built up front by interpret()
     *
     * @param  ClassDeclarationAST  $node  The node to visit
     * @return null Declarations produce no result
     */
    public function visitClassDeclaration(ClassDeclarationAST $node): null
    {
        return null;
    }

    /**
     * Visit a This node (#)
     *
     * @param  ThisAST  $node  The node to visit
     * @return ObjectValue The object the running method was called on
     */
    public function visitThis(ThisAST $node): ObjectValue
    {
        return $this->receiver;
    }

    /**
     * Visit a Property node: a field's value, or a method bound to the object
     *
     * @param  PropertyAST  $node  The node to visit
     * @return mixed The member's value
     */
    public function visitProperty(PropertyAST $node)
    {
        $target = $this->visit($node->target);

        return $node->existing ? Values::propertyExisting($target, $node->name) : Values::property($target, $node->name);
    }

    /**
     * Visit a MethodCall node: the object, then the member, then the arguments, then the call
     *
     * The same order and errors as reading the member and calling what it gives, but a
     * method runs directly, without making a bound method first.
     *
     * @param  MethodCallAST  $node  The node to visit
     * @return mixed The returned value
     */
    public function visitMethodCall(MethodCallAST $node)
    {
        $target = $this->visit($node->property->target);
        $name = $node->property->name;
        if (! $target instanceof ObjectValue || ! isset($target->class->methods[$name]) || $name === '_') {
            $callee = Values::property($target, $name);
            $args = array_map(fn ($arg) => $this->visit($arg), $node->args);
            $this->call_site = [$node->file, $node->line];

            return $this->callValue($callee, $args);
        }

        $definer = $target->class->methods[$name];
        $args = array_map(fn ($arg) => $this->visit($arg), $node->args);
        $this->call_site = [$node->file, $node->line];
        $arity = $this->functions["{$definer->name}.{$name}"]->arity;
        if (! Builtins::fitsArity($arity, count($args))) {
            throw new Exception(Builtins::arityError("Method {$definer->name}.{$name}", $arity, count($args)));
        }

        return $this->invokeMethod($definer, $name, $target, $args);
    }

    /**
     * Visit a ParentMethod node: ##name(args) runs the parent's version on this object, ##name binds it
     *
     * @param  ParentMethodAST  $node  The node to visit
     * @return mixed The returned value, or the bound method
     */
    public function visitParentMethod(ParentMethodAST $node)
    {
        $definer = $this->classes[$node->definer];
        if ($node->args === null) {
            return FunctionValue::bound($this->receiver, $definer, $node->name);
        }
        $args = array_map(fn ($arg) => $this->visit($arg), $node->args);
        $this->call_site = [$node->file, $node->line];

        return $this->invokeMethod($definer, $node->name, $this->receiver, $args);
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
     * Visit a Lambda node, making a closure with copies of the outer variables it captures, now
     *
     * Only variables that exist are copied: one that doesn't is undefined inside until the
     * closure sets it (with ??=). With $f = <lambda>, the closure's $f is the closure itself.
     *
     * @param  LambdaAST  $node  The node to visit
     */
    public function visitLambda(LambdaAST $node): FunctionValue
    {
        $captured = [];
        foreach ($node->captures as $name) {
            $outer = isset($this->captures[$name]) ? $this->closure->captured : $this->locals;
            if (array_key_exists($name, $outer)) {
                $captured[$name] = $outer[$name];
            }
        }
        $closure = FunctionValue::closure($node, $captured, null, $this->receiver, $node->file, $node->line);
        if ($node->self !== null) {
            $closure->captured[$node->self] = $closure;
        }

        return $closure;
    }

    /**
     * Visit a FunctionRef node
     *
     * @param  FunctionRefAST  $node  The node to visit
     * @return FunctionValue The function as a value
     */
    public function visitFunctionRef(FunctionRefAST $node): FunctionValue|ClassValue
    {
        return $this->classes[$node->name] ?? FunctionValue::named($node->name);
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
        $args = array_map(fn ($arg) => $this->visit($arg), $node->args);
        $this->call_site = [$node->file, $node->line];
        if (isset($this->classes[$node->name])) {
            return $this->construct($this->classes[$node->name], $args);
        }

        return $this->call($node->name, $args);
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
        $this->call_site = [$node->file, $node->line];

        return $this->callValue($callee, $args);
    }

    /**
     * Call a value with evaluated arguments, checking it can be called with that many
     *
     * @param  mixed  $callee  The value: a function, closure, bound method or class
     * @param  array  $args  The evaluated arguments
     * @return mixed The returned value, or the new object
     *
     * @throws Exception If the callee can't be called, or not with that many arguments
     */
    private function callValue($callee, array $args)
    {
        if ($callee instanceof ClassValue) {
            if ($callee->abstract) {
                throw new Exception("Cannot construct abstract class {$callee->name}");
            }
            if (! Builtins::fitsArity($callee->arity, count($args))) {
                throw new Exception(Builtins::arityError("Class {$callee->name}", $callee->arity, count($args)));
            }

            return $this->construct($callee, $args);
        }
        if (! $callee instanceof FunctionValue) {
            throw new Exception('Cannot call '.Values::typeOf($callee));
        }
        $arity = match (true) {
            $callee->lambda !== null => $callee->lambda->arity,
            $callee->class !== null => $this->functions["{$callee->class->name}.{$callee->name}"]->arity,
            default => Builtins::ARITIES[$callee->name] ?? $this->functions[$callee->name]->arity,
        };
        if (! Builtins::fitsArity($arity, count($args))) {
            throw new Exception(Builtins::arityError($callee->title(), $arity, count($args)));
        }

        if ($callee->lambda !== null) {
            $lambda = $callee->lambda;

            return $this->invoke($callee->describe(), $lambda->params, $lambda->defaults, $lambda->body, $callee, $args, $callee->receiver);
        }
        if ($callee->class !== null) {
            return $this->invokeMethod($callee->class, $callee->name, $callee->receiver, $args);
        }

        return $this->call($callee->name, $args);
    }

    /**
     * Run a class's version of a method on an object
     *
     * @param  ClassValue  $definer  The class whose version runs
     * @param  string  $name  The method name
     * @param  ObjectValue  $receiver  The object # is
     * @param  array  $args  The evaluated arguments, already checked against the arity
     * @return mixed The returned value
     */
    private function invokeMethod(ClassValue $definer, string $name, ObjectValue $receiver, array $args)
    {
        $method = $this->functions["{$definer->name}.{$name}"];

        return $this->invoke("{$definer->name}.{$name}", $method->params, $method->defaults, $method->body, null, $args, $receiver);
    }

    /**
     * Make a new object: set its field defaults, parent's first, then run the constructor
     *
     * Both run as one call, which is one level of call depth, with the constructor a call
     * inside it, as in the VM. The class is already checked to be constructible with this
     * many arguments.
     *
     * @param  ClassValue  $class  The class
     * @param  array  $args  The evaluated arguments for the constructor
     * @return ObjectValue The new object
     *
     * @throws Exception If the call depth limit is reached, or a default or the constructor fails
     */
    private function construct(ClassValue $class, array $args): ObjectValue
    {
        if ($this->call_depth === Values::MAX_CALL_DEPTH) {
            throw new Exception('Maximum call depth of '.Values::MAX_CALL_DEPTH." exceeded calling {$class->name}");
        }

        $object = new ObjectValue($class);
        $this->calls[] = ["new {$class->name}", ...($this->call_site ?? [null, null])];
        $caller = [$this->locals, $this->closure, $this->captures, $this->receiver];
        [$this->locals, $this->closure, $this->captures, $this->receiver] = [[], null, [], $object];
        $this->call_depth++;
        try {
            foreach ($class->fields as $field => $declarer) {
                $default = $this->declarations[$declarer]->fields[$field];
                if ($default !== null) {
                    $object->fields[$field] = $this->visit($default);
                }
            }
            if (isset($class->methods['_'])) {
                // The constructor is called by the class, as the VM's initialiser calls it
                $declaration = $this->declarations[$class->name];
                $this->call_site = [$declaration->file, $declaration->line];
                $this->invokeMethod($class->methods['_'], '_', $object, $args);
            }
        } finally {
            array_pop($this->calls);
            [$this->locals, $this->closure, $this->captures, $this->receiver] = $caller;
            $this->call_depth--;
        }

        return $object;
    }

    /**
     * Call a builtin or user function by name with evaluated arguments
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

        return $this->invoke($name, $function->params, $function->defaults, $function->body, null, $args);
    }

    /**
     * Run a function or lambda body with its own locals
     *
     * @param  string  $display  How the function is named in the depth error
     * @param  string[]  $params  The parameter names
     * @param  array<int, AST|null>  $defaults  Each parameter's default, or null
     * @param  AST  $body  A block (returning through return, else null) or a lambda's expression body
     * @param  FunctionValue|null  $closure  The closure being called, whose captured variables the body uses, or null
     * @param  array  $args  The evaluated arguments, no more than there are parameters
     * @param  ObjectValue|null  $receiver  The object # is in the body, or null outside a class
     * @return mixed The returned value
     *
     * @throws Exception If the call depth limit is reached
     */
    private function invoke(string $display, array $params, array $defaults, AST $body, ?FunctionValue $closure, array $args, ?ObjectValue $receiver = null)
    {
        if ($this->call_depth === Values::MAX_CALL_DEPTH) {
            throw new Exception('Maximum call depth of '.Values::MAX_CALL_DEPTH." exceeded calling {$display}");
        }

        // A lambda is "->": the trace says where it was running, not where it was written
        $this->calls[] = [$closure === null ? $display : '->', ...($this->call_site ?? [null, null])];
        [$caller_locals, $caller_closure, $caller_captures, $caller_receiver] = [$this->locals, $this->closure, $this->captures, $this->receiver];
        $this->locals = array_combine(array_slice($params, 0, count($args)), $args);
        $this->receiver = $receiver;
        // Captured variables live in the closure, so every call of it, recursive ones too, shares them
        [$this->closure, $this->captures] = [$closure, $closure === null ? [] : $closure->lambda->capture_names];
        $this->call_depth++;

        try {
            // Defaults are evaluated on every call that leaves them out, inside the function, so
            // they can use earlier parameters and never share a value between calls
            for ($i = count($args); $i < count($params); $i++) {
                $this->locals[$params[$i]] = $this->visit($defaults[$i]);
            }

            if (! $body instanceof CompoundAST) {
                return $this->visit($body);
            }
            $this->visit($body);

            return null;
        } catch (ReturnSignal $signal) {
            // The signal is reused, so let go of the value: holding a second reference
            // to a returned array would make the next write to it copy the whole array
            $value = $signal->value;
            $signal->value = null;

            return $value;
        } finally {
            array_pop($this->calls);
            [$this->locals, $this->closure, $this->captures, $this->receiver] = [$caller_locals, $caller_closure, $caller_captures, $caller_receiver];
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

        // Register every function and class first, so they can be used before they are declared.
        // Methods are functions keyed "Class.name", which no function name can be, as in the Program
        $records = [];
        foreach ($tree->statements as $statement) {
            if ($statement instanceof FunctionDeclarationAST) {
                $this->functions[$statement->name] = $statement;
            } elseif ($statement instanceof ClassDeclarationAST) {
                $records[$statement->name] = $statement->record();
                $this->declarations[$statement->name] = $statement;
                foreach ($statement->methods as $method) {
                    if (! $method->abstract) {
                        $this->functions["{$statement->name}.{$method->name}"] = $method;
                    }
                }
            }
        }
        $this->classes = ClassValue::build($records, array_map(fn (FunctionDeclarationAST $function) => $function->arity, $this->functions));

        // echo and .. call to_string() through Values, which comes back here to run it
        $outer = Values::$call_method;
        Values::$call_method = function (ObjectValue $object, ClassValue $definer, string $name) {
            // Printing runs to_string() outside the program's own calls, as the VM runs it in
            // a loop of its own, so the trace of an error inside it starts there
            $this->call_site = null;

            return $this->invokeMethod($definer, $name, $object, []);
        };
        try {
            $this->visit($tree);
        } catch (GazLangError $error) {
            // Nothing caught it: a thrown value is only now turned into text, which can run its to_string()
            throw $error->uncaught();
        } finally {
            Values::$call_method = $outer;
        }
    }
}
