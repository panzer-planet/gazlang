<?php

namespace GazLang\Interpreter;

use Exception;
use GazLang\AST\BinOp;
use GazLang\AST\Compound;
use GazLang\AST\Num;
use GazLang\AST\Statement;
use GazLang\AST\EchoStatement;
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
     * Constructor
     *
     * @param Parser $parser The parser to get the AST from
     */
    public function __construct(Parser $parser)
    {
        $this->parser = $parser;
    }
    
    /**
     * Visit a BinOp node
     *
     * @param BinOp $node The node to visit
     * 
     * @return int The result of the binary operation
     */
    public function visit_BinOp(BinOp $node): int
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
     * @param Num $node The node to visit
     * 
     * @return int The numeric value
     */
    public function visit_Num(Num $node): int
    {
        return $node->value;
    }
    
    /**
     * Visit a Statement node
     *
     * @param Statement $node The node to visit
     * 
     * @return int The result of the statement
     */
    public function visit_Statement(Statement $node): int
    {
        // Evaluate the expression but don't output it
        return $this->visit($node->expr);
    }
    
    /**
     * Visit an EchoStatement node
     *
     * @param EchoStatement $node The node to visit
     * 
     * @return int The result of the echo statement
     */
    public function visit_EchoStatement(EchoStatement $node): int
    {
        $result = $this->visit($node->expr);
        // Print the result directly to the terminal
        echo $result . PHP_EOL;
        return $result;
    }
    
    /**
     * Visit a Compound node
     *
     * @param Compound $node The node to visit
     * 
     * @return array The results of each statement
     */
    public function visit_Compound(Compound $node): array
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