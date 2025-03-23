<?php

namespace GazLang\CodeGenerator;

use Exception;
use GazLang\AST\BinOp;
use GazLang\AST\Compound;
use GazLang\AST\Num;
use GazLang\AST\Statement;
use GazLang\AST\EchoStatement;
use GazLang\AST\Variable;
use GazLang\AST\Assign;
use GazLang\Lexer\Token;

/**
 * CodeGenerator class transforms the AST into stack-based VM code
 */
class CodeGenerator
{
    /**
     * @var object The AST to generate code from
     */
    private $tree;
    
    /**
     * @var array The generated instructions
     */
    private $instructions;
    
    /**
     * @var array Map of variable names to memory addresses
     */
    private $var_addresses;
    
    /**
     * @var int Next available memory address for variable storage
     */
    private $next_address;
    
    /**
     * Constructor
     *
     * @param object $tree The AST to generate code from
     */
    public function __construct(object $tree)
    {
        $this->tree = $tree;
        $this->instructions = [];
        $this->var_addresses = [];
        $this->next_address = 0;
    }
    
    /**
     * Visit a Variable node
     *
     * @param Variable $node The node to visit
     */
    public function visit_Variable(Variable $node): void
    {
        $var_name = $node->value;
        if (!isset($this->var_addresses[$var_name])) {
            throw new Exception("Undefined variable: {$var_name}");
        }
        
        // Load the variable's value onto the stack
        $this->instructions[] = "LOAD {$this->var_addresses[$var_name]}";
    }
    
    /**
     * Visit an Assign node
     *
     * @param Assign $node The node to visit
     */
    public function visit_Assign(Assign $node): void
    {
        $var_name = $node->left->value;
        
        // Allocate memory for the variable if not already allocated
        if (!isset($this->var_addresses[$var_name])) {
            $this->var_addresses[$var_name] = $this->next_address++;
        }
        
        // Generate code for the right-hand side of the assignment
        $this->visit($node->right);
        
        // Store the computed value in the variable's memory location
        $this->instructions[] = "STORE {$this->var_addresses[$var_name]}";
        
        // Leave the value on the stack for potential use in larger expressions
        $this->instructions[] = "LOAD {$this->var_addresses[$var_name]}";
    }
    
    /**
     * Visit a BinOp node
     *
     * @param BinOp $node The node to visit
     */
    public function visit_BinOp(BinOp $node): void
    {
        // Visit left and right nodes first (post-order traversal)
        $this->visit($node->left);
        $this->visit($node->right);
        
        // Now emit the operation instruction
        if ($node->op->type === Token::PLUS) {
            $this->instructions[] = 'ADD';
        } else if ($node->op->type === Token::MINUS) {
            $this->instructions[] = 'SUB';
        } else if ($node->op->type === Token::MULTIPLY) {
            $this->instructions[] = 'MUL';
        } else if ($node->op->type === Token::DIVIDE) {
            $this->instructions[] = 'DIV';
        } else {
            throw new Exception("Unknown operator: {$node->op->type}");
        }
    }
    
    /**
     * Visit a Num node
     *
     * @param Num $node The node to visit
     */
    public function visit_Num(Num $node): void
    {
        // Push the number onto the stack
        $this->instructions[] = "PUSH {$node->value}";
    }
    
    /**
     * Visit a Statement node
     *
     * @param Statement $node The node to visit
     */
    public function visit_Statement(Statement $node): void
    {
        // Evaluate the expression but don't output
        $this->visit($node->expr);
        $this->instructions[] = 'POP';  // Just pop the result off the stack, no output
    }
    
    /**
     * Visit an EchoStatement node
     *
     * @param EchoStatement $node The node to visit
     */
    public function visit_EchoStatement(EchoStatement $node): void
    {
        $this->visit($node->expr);
        $this->instructions[] = 'PRINT';  // Output the result
    }
    
    /**
     * Visit a Compound node
     *
     * @param Compound $node The node to visit
     */
    public function visit_Compound(Compound $node): void
    {
        foreach ($node->statements as $statement) {
            $this->visit($statement);
        }
    }
    
    /**
     * Visit a node
     *
     * @param object $node The node to visit
     * @throws Exception If there's no visitor method for the node type
     */
    public function visit(object $node): void
    {
        $method = 'visit_' . get_class($node);
        
        // Remove namespace from the method name
        $method = str_replace('GazLang\\AST\\', '', $method);
        
        if (method_exists($this, $method)) {
            $this->$method($node);
            return;
        }
        
        throw new Exception("No {$method} method");
    }
    
    /**
     * Generate code from the AST
     *
     * @return string The generated code
     */
    public function generate(): string
    {
        $this->visit($this->tree);
        return implode("\n", $this->instructions);
    }
} 