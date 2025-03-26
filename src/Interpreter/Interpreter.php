<?php

namespace GazLang\Interpreter;

use Exception;
use GazLang\AST\AbstractNodeVisitor;
use GazLang\AST\BinOpAST;
use GazLang\AST\CompoundAST;
use GazLang\AST\NumAST;
use GazLang\AST\StatementAST;
use GazLang\AST\EchoStatementAST;
use GazLang\AST\IfStatementAST;
use GazLang\AST\VariableAST;
use GazLang\AST\AssignAST;
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
     * @param Parser $parser The parser to get the AST from
     */
    public function __construct(Parser $parser)
    {
        $this->parser = $parser;
        $this->symbol_table = [];
    }
    
    /**
     * Visit a Variable node
     *
     * @param VariableAST $node The node to visit
     * 
     * @return int The value of the variable
     * @throws Exception If the variable is not defined
     */
    public function visitVariable(VariableAST $node): int
    {
        $var_name = $node->value;
        if (!isset($this->symbol_table[$var_name])) {
            throw new Exception("Undefined variable: {$var_name}");
        }
        return $this->symbol_table[$var_name];
    }
    
    /**
     * Visit an Assign node
     *
     * @param AssignAST $node The node to visit
     * 
     * @return int The value assigned to the variable
     */
    public function visitAssign(AssignAST $node): int
    {
        $var_name = $node->left->value;
        $var_value = $this->visit($node->right);
        $this->symbol_table[$var_name] = $var_value;
        return $var_value;
    }
    
    /**
     * Visit a BinOp node
     *
     * @param BinOpAST $node The node to visit
     * 
     * @return int The result of the binary operation
     */
    public function visitBinOp(BinOpAST $node): int
    {
        if ($node->op->type === Token::PLUS) {
            return $this->visit($node->left) + $this->visit($node->right);
        } else if ($node->op->type === Token::MINUS) {
            return $this->visit($node->left) - $this->visit($node->right);
        } else if ($node->op->type === Token::MULTIPLY) {
            return $this->visit($node->left) * $this->visit($node->right);
        } else if ($node->op->type === Token::DIVIDE) {
            return intdiv($this->visit($node->left), $this->visit($node->right)); // Integer division
        }
        
        throw new Exception("Unknown operator: {$node->op->type}");
    }
    
    /**
     * Visit a Num node
     *
     * @param NumAST $node The node to visit
     * 
     * @return int The numeric value
     */
    public function visitNum(NumAST $node): int
    {
        return $node->value;
    }
    
    /**
     * Visit a Statement node
     *
     * @param StatementAST $node The node to visit
     * 
     * @return int The result of the statement
     */
    public function visitStatement(StatementAST $node): int
    {
        // Evaluate the expression but don't output it
        return $this->visit($node->expr);
    }
    
    /**
     * Visit an EchoStatement node
     *
     * @param EchoStatementAST $node The node to visit
     * 
     * @return int The result of the echo statement
     */
    public function visitEchoStatement(EchoStatementAST $node): int
    {
        $result = $this->visit($node->expr);
        // Print the result directly to the terminal
        echo $result . PHP_EOL;
        return $result;
    }
    
    /**
     * Visit a Compound node
     *
     * @param CompoundAST $node The node to visit
     * 
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
     * @param IfStatementAST $node The node to visit
     * 
     * @return mixed The result of the executed branch
     */
    public function visitIfStatement(IfStatementAST $node)
    {
        // Evaluate the condition
        $condition_value = $this->visit($node->condition);
        
        // In C-like fashion, any non-zero value is considered true
        if ($condition_value != 0) {
            // Execute the if branch
            return $this->visit($node->if_body);
        } else if ($node->else_if !== null) {
            // Execute the else-if branch if it exists
            return $this->visit($node->else_if);
        } else if ($node->else_body !== null) {
            // Execute the else branch if it exists
            return $this->visit($node->else_body);
        }
        
        // If condition is false and there's no else block, return empty result
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