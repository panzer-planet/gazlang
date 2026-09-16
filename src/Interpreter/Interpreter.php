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
use GazLang\Lexer\Token;
use GazLang\Parser\Parser;

/**
 * Interpreter class evaluates the AST
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
     */
    public function __construct(Parser $parser)
    {
        $this->parser = $parser;
        $this->return_signal = new ReturnSignal;
        $this->loop_signals = [
            Token::BREAK => new LoopSignal(Token::BREAK),
            Token::CONTINUE => new LoopSignal(Token::CONTINUE),
        ];
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
            $keys[] = $index === null ? null : $this->arrayKey($this->visit($index));
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
            return $this->isTruthy($this->visit($node->left)) && $this->isTruthy($this->visit($node->right));
        } elseif ($node->op->type === Token::OR) {
            return $this->isTruthy($this->visit($node->left)) || $this->isTruthy($this->visit($node->right));
        }

        $left = $this->visit($node->left);
        $right = $this->visit($node->right);

        // If either operand is a string, plus performs string concatenation
        if ($node->op->type === Token::PLUS && (is_string($left) || is_string($right))) {
            return $this->toString($left).$this->toString($right);
        }

        // Strict equality compares type and value as they are, before any conversion
        if ($node->op->type === Token::STRICT_EQUALS) {
            return $left === $right;
        } elseif ($node->op->type === Token::STRICT_NOT_EQUALS) {
            return $left !== $right;
        }

        // null only equals null; arithmetic and ordering on it are errors
        if ($left === null || $right === null) {
            return match ($node->op->type) {
                Token::EQUALS => $left === $right,
                Token::NOT_EQUALS => $left !== $right,
                default => throw new Exception("Cannot use {$node->op->value} on null"),
            };
        }

        // Arrays are equal when they have the same keys, in the same order, with identical values
        if (is_array($left) || is_array($right)) {
            return match ($node->op->type) {
                Token::EQUALS => $left === $right,
                Token::NOT_EQUALS => $left !== $right,
                default => throw new Exception("Cannot use {$node->op->value} on array"),
            };
        }

        // Everywhere else booleans act as 1/0, so true + 1 is 2 and true == 1
        $left = is_bool($left) ? (int) $left : $left;
        $right = is_bool($right) ? (int) $right : $right;

        $type = $node->op->type;

        if (in_array($type, [Token::PLUS, Token::MINUS, Token::MULTIPLY, Token::DIVIDE], true)) {
            if (is_string($left) || is_string($right)) {
                throw new Exception("Cannot use {$node->op->value} on string");
            }

            return match ($type) {
                Token::PLUS => $left + $right,
                Token::MINUS => $left - $right,
                Token::MULTIPLY => $left * $right,
                Token::DIVIDE => intdiv($left, $right),
            };
        }

        // Two strings compare byte by byte, so "1" != "01" and "10" < "9";
        // anything else uses PHP's comparison, which matches == for scalars
        $cmp = is_string($left) && is_string($right) ? strcmp($left, $right) : $left <=> $right;

        return match ($type) {
            Token::EQUALS => $cmp === 0,
            Token::NOT_EQUALS => $cmp !== 0,
            Token::LESS_THAN => $cmp < 0,
            Token::LESS_EQUALS => $cmp <= 0,
            Token::GREATER_THAN => $cmp > 0,
            Token::GREATER_EQUALS => $cmp >= 0,
            default => throw new Exception("Unknown operator: {$type}"),
        };
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
            return ! $this->isTruthy($value);
        } elseif ($node->op->type === Token::MINUS) {
            if (! is_int($value) && ! is_bool($value)) {
                throw new Exception('Cannot use - on '.get_debug_type($value));
            }

            return -(int) $value;
        }

        throw new Exception("Unknown operator: {$node->op->type}");
    }

    /**
     * Decide whether a value counts as true in conditions and logical operators
     *
     * Strings and arrays are true unless empty, null is false; everything else is C-like, true unless 0.
     *
     * @param  mixed  $value  The value to test
     */
    private function isTruthy($value): bool
    {
        return match (true) {
            is_string($value) => $value !== '',
            is_array($value) => $value !== [],
            default => $value !== null && $value != 0,
        };
    }

    /**
     * Convert a value to string for string operations
     *
     * @param  mixed  $value  The value to convert
     * @return string The string representation
     */
    private function toString($value): string
    {
        if (is_string($value)) {
            return $value;
        } elseif (is_int($value)) {
            return (string) $value;
        } elseif (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif ($value === null) {
            return 'null';
        } elseif (is_array($value)) {
            // Printed as a literal: [1, "a"] for lists, ["key" => 1, 5 => 2] otherwise
            $is_list = array_is_list($value);
            $parts = [];
            foreach ($value as $key => $item) {
                $item = is_string($item) ? $this->quote($item) : $this->toString($item);
                $parts[] = $is_list ? $item : (is_string($key) ? $this->quote($key) : $key).' => '.$item;
            }

            return '['.implode(', ', $parts).']';
        }

        throw new Exception('Cannot convert '.get_debug_type($value).' to string');
    }

    /**
     * Quote a string the way it would be written in source, for printing inside arrays
     *
     * @param  string  $value  The string to quote
     */
    private function quote(string $value): string
    {
        return '"'.addcslashes($value, "\"\n\r\t\\").'"';
    }

    /**
     * Check a value can be used as an array key
     *
     * PHP stores numeric string keys like "1" as the integer 1, so they name the same element.
     *
     * @param  mixed  $key  The key value
     * @return int|string The key
     *
     * @throws Exception If the key is not an int or string
     */
    private function arrayKey($key): int|string
    {
        if (! is_int($key) && ! is_string($key)) {
            throw new Exception('Array keys must be int or string, got '.get_debug_type($key));
        }

        return $key;
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
        echo $this->toString($result).PHP_EOL;

        return $result;
    }

    /**
     * Visit a Compound node
     *
     * @param  CompoundAST  $node  The node to visit
     * @return array The results of each statement
     */
    public function visitCompound(CompoundAST $node): array
    {
        $results = [];
        foreach ($node->statements as $statement) {
            $results[] = $this->visit($statement);
        }

        return $results;
    }

    /**
     * Visit an IfStatement node
     *
     * @param  IfStatementAST  $node  The node to visit
     * @return mixed The result of the executed branch
     */
    public function visitIfStatement(IfStatementAST $node)
    {
        if ($this->isTruthy($this->visit($node->condition))) {
            // Execute the if branch
            return $this->visit($node->if_body);
        } elseif ($node->else_if !== null) {
            // Execute the else-if branch if it exists
            return $this->visit($node->else_if);
        } elseif ($node->else_body !== null) {
            // Execute the else branch if it exists
            return $this->visit($node->else_body);
        }

        // If condition is false and there's no else block, return empty result
        return [];
    }

    /**
     * Visit a WhileStatement node
     *
     * @param  WhileStatementAST  $node  The node to visit
     * @return array Loops produce no result
     */
    public function visitWhileStatement(WhileStatementAST $node): array
    {
        while ($this->isTruthy($this->visit($node->condition))) {
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

        return [];
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
                $array[$this->arrayKey($this->visit($key))] = $this->visit($value);
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

        if (is_array($target)) {
            return $target[$this->arrayKey($index)] ?? null;
        } elseif (is_string($target)) {
            if (! is_int($index)) {
                throw new Exception('String positions must be int, got '.get_debug_type($index));
            }

            return $index >= 0 && $index < strlen($target) ? $target[$index] : null;
        }

        throw new Exception('Cannot use [] on '.get_debug_type($target));
    }

    /**
     * Visit a FunctionDeclaration node; declarations are registered up front by interpret()
     *
     * @param  FunctionDeclarationAST  $node  The node to visit
     * @return array Declarations produce no result
     */
    public function visitFunctionDeclaration(FunctionDeclarationAST $node): array
    {
        return [];
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

        if (isset(Parser::BUILTINS[$node->name])) {
            return $this->callBuiltin($node->name, $args);
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
     * Run a builtin function; the parser has checked the name and argument count
     *
     * @param  string  $name  The builtin name, a key of Parser::BUILTINS
     * @param  array  $args  The evaluated arguments
     * @return mixed The result
     */
    private function callBuiltin(string $name, array $args)
    {
        return match ($name) {
            'len' => match (true) {
                is_array($args[0]) => count($args[0]),
                is_string($args[0]) => strlen($args[0]),
                default => throw new Exception('len() expects an array or string, got '.get_debug_type($args[0])),
            },
            default => throw new Exception("Unknown builtin: {$name}"),
        };
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

    // The visit method is now implemented in AbstractNodeVisitor

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
