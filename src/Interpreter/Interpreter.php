<?php

namespace GazLang\Interpreter;

use Exception;
use GazLang\AST\AbstractNodeVisitor;
use GazLang\AST\AssignAST;
use GazLang\AST\BinOpAST;
use GazLang\AST\BooleanAST;
use GazLang\AST\CompoundAST;
use GazLang\AST\EchoStatementAST;
use GazLang\AST\IfStatementAST;
use GazLang\AST\NumAST;
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
     * @var Parser The parser that provides the AST
     */
    private $parser;

    /**
     * @var array Symbol table to store variable values
     */
    private $symbol_table;

    /**
     * Constructor
     *
     * @param  Parser  $parser  The parser to get the AST from
     */
    public function __construct(Parser $parser)
    {
        $this->parser = $parser;
        $this->symbol_table = [];
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
        if (! isset($this->symbol_table[$var_name])) {
            throw new Exception("Undefined variable: {$var_name}");
        }

        return $this->symbol_table[$var_name];
    }

    /**
     * Visit an Assign node
     *
     * @param  AssignAST  $node  The node to visit
     * @return mixed The value assigned to the variable
     */
    public function visitAssign(AssignAST $node)
    {
        $var_name = $node->left->value;
        $var_value = $this->visit($node->right);
        $this->symbol_table[$var_name] = $var_value;

        return $var_value;
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

        // Everywhere else booleans act as 1/0, so true + 1 is 2 and true == 1
        $left = is_bool($left) ? (int) $left : $left;
        $right = is_bool($right) ? (int) $right : $right;

        $type = $node->op->type;

        if (in_array($type, [Token::PLUS, Token::MINUS, Token::MULTIPLY, Token::DIVIDE], true)) {
            if (is_string($left) || is_string($right)) {
                throw new Exception("Cannot use {$node->op->value} on strings");
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
            if (is_string($value)) {
                throw new Exception('Cannot use - on strings');
            }

            return -(int) $value;
        }

        throw new Exception("Unknown operator: {$node->op->type}");
    }

    /**
     * Decide whether a value counts as true in conditions and logical operators
     *
     * Strings are true unless empty; everything else is C-like, true unless 0.
     *
     * @param  mixed  $value  The value to test
     */
    private function isTruthy($value): bool
    {
        return is_string($value) ? $value !== '' : $value != 0;
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
        }

        throw new Exception('Cannot convert '.get_debug_type($value).' to string');
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
            $this->visit($node->body);
        }

        return [];
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
        $this->visit($tree);
    }
}
