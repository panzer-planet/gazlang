<?php

namespace GazLang\Interpreter;

use Exception;
use GazLang\AST\BinOpAST;
use GazLang\AST\CompoundAST;
use GazLang\AST\NumAST;
use GazLang\AST\StatementAST;
use GazLang\AST\EchoStatementAST;
use GazLang\AST\VariableAST;
use GazLang\AST\AssignAST;
use GazLang\Lexer\Token;
use GazLang\Parser\Parser;

/**
 * Interpreter class evaluates the AST
 */
class Interpreter
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
    public function visit_Variable(VariableAST $node): int
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
    public function visit_Assign(AssignAST $node): int
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
    public function visit_BinOp(BinOpAST $node): int
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
    public function visit_Num(NumAST $node): int
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
    public function visit_Statement(StatementAST $node): int
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
    public function visit_EchoStatement(EchoStatementAST $node): int
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
    public function visit_Compound(CompoundAST $node): array
    {
        $results = [];
        foreach ($node->statements as $statement) {
            $results[] = $this->visit($statement);
        }
        return $results;
    }
    
    /**
     * Visit a node
     *
     * @param object $node The node to visit
     * 
     * @return mixed The result of visiting the node
     * @throws Exception If there's no visitor method for the node type
     */
    public function visit(object $node)
    {
        $method = 'visit_' . get_class($node);
        
        // Remove namespace from the method name
        $method = str_replace('GazLang\\AST\\', '', $method);
        // Remove AST suffix from class name
        $method = str_replace('AST', '', $method);
        
        if (method_exists($this, $method)) {
            return $this->$method($node);
        }
        
        throw new Exception("No {$method} method");
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