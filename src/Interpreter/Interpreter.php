<?php

namespace GazLang\Interpreter;

use Exception;
use GazLang\AST\AbstractNodeVisitor;
use GazLang\AST\AssignAST;
use GazLang\AST\BinOpAST;
use GazLang\AST\BoolAST;
use GazLang\AST\CompoundAST;
use GazLang\AST\EchoStatementAST;
use GazLang\AST\IfStatementAST;
use GazLang\AST\NumAST;
use GazLang\AST\StatementAST;
use GazLang\AST\StringAST;
use GazLang\AST\UnaryOpAST;
use GazLang\AST\VariableAST;
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
    private array $symbol_table;

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
            throw new Exception("Undefined variable: $var_name");
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
     * @return mixed The result of the binary operation (int or string)
     */
    public function visitBinOp(BinOpAST $node)
    {
        // Logical operators need short-circuit evaluation
        if ($node->op->type === Token::AND) {
            // Evaluate the left operand first
            $left = $this->visit($node->left);
            $leftBool = $this->toBool($left);

            // Short-circuit: if left is false, return false without evaluating right
            if (! $leftBool) {
                return false;
            }

            // Evaluate right operand and return its boolean value
            $right = $this->visit($node->right);

            return $this->toBool($right);
        } elseif ($node->op->type === Token::OR) {
            // Evaluate the left operand first
            $left = $this->visit($node->left);
            $leftBool = $this->toBool($left);

            // Short-circuit: if left is true, return true without evaluating right
            if ($leftBool) {
                return true;
            }

            // Evaluate right operand and return its boolean value
            $right = $this->visit($node->right);

            return $this->toBool($right);
        }

        // For other operators, evaluate both operands
        $left = $this->visit($node->left);
        $right = $this->visit($node->right);

        if ($node->op->type === Token::PLUS) {
            // If either operand is a string, perform string concatenation
            if (is_string($left) || is_string($right)) {
                return $this->toString($left).$this->toString($right);
            }

            // Otherwise, perform numeric addition
            return $left + $right;
        } elseif ($node->op->type === Token::MINUS) {
            // String operation not supported for minus
            if (is_string($left) || is_string($right)) {
                throw new Exception('Cannot perform subtraction on strings');
            }

            return $left - $right;
        } elseif ($node->op->type === Token::MULTIPLY) {
            // String operation not supported for multiply
            if (is_string($left) || is_string($right)) {
                throw new Exception('Cannot perform multiplication on strings');
            }

            return $left * $right;
        } elseif ($node->op->type === Token::DIVIDE) {
            // String operation not supported for divide
            if (is_string($left) || is_string($right)) {
                throw new Exception('Cannot perform division on strings');
            }

            return intdiv($left, $right); // Integer division
        } elseif ($node->op->type === Token::EQUALS) {
            // Equals operator works for both numbers and strings
            return $left == $right ? 1 : 0; // Return 1 for true, 0 for false
        } elseif ($node->op->type === Token::NOT_EQUALS) {
            // Not equals operator works for both numbers and strings
            return $left != $right ? 1 : 0; // Return 1 for true, 0 for false
        }

        throw new Exception("Unknown operator: {$node->op->type}");
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
            return $value ? '1' : '0';
        } else {
            return '';
        }
    }

    /**
     * Convert a value to boolean for boolean operations
     *
     * @param  mixed  $value  The value to convert
     * @return bool The boolean representation
     */
    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        } elseif (is_int($value)) {
            return $value !== 0;
        } elseif (is_string($value)) {
            return $value !== '';
        } else {
            return false;
        }
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
     * Visit a Bool node
     *
     * @param  BoolAST  $node  The node to visit
     * @return bool The boolean value
     */
    public function visitBool(BoolAST $node): bool
    {
        return $node->value;
    }

    /**
     * Visit a UnaryOp node
     *
     * @param  UnaryOpAST  $node  The node to visit
     * @return bool The result of the unary operation
     */
    public function visitUnaryOp(UnaryOpAST $node): bool
    {
        $operand = $this->visit($node->expr);

        if ($node->op->type === Token::NOT) {
            // Perform type coercion to boolean and return the negation
            return ! $this->toBool($operand);
        }

        throw new Exception("Unknown unary operator: {$node->op->type}");
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

        if (is_bool($result)) {
            $output = $result ? '1' : '0';
        } else {
            $output = $result;
        }
        // Print the result directly to the terminal
        echo $output.PHP_EOL;

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
        // Evaluate the condition
        $condition_value = $this->visit($node->condition);

        // Use toBool for consistent type coercion
        if ($this->toBool($condition_value)) {
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
