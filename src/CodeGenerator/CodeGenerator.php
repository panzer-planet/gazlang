<?php

namespace GazLang\CodeGenerator;

use Exception;
use GazLang\AST\BinOpAST;
use GazLang\AST\CompoundAST;
use GazLang\AST\NumAST;
use GazLang\AST\StatementAST;
use GazLang\AST\EchoStatementAST;
use GazLang\AST\IfStatementAST;
use GazLang\AST\VariableAST;
use GazLang\AST\AssignAST;
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
     * @var int Counter for generating unique labels
     */
    private $label_counter;
    
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
        $this->label_counter = 0;
    }
    
    /**
     * Visit a Variable node
     *
     * @param VariableAST $node The node to visit
     */
    public function visit_Variable(VariableAST $node): void
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
     * @param AssignAST $node The node to visit
     */
    public function visit_Assign(AssignAST $node): void
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
     * @param BinOpAST $node The node to visit
     */
    public function visit_BinOp(BinOpAST $node): void
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
     * @param NumAST $node The node to visit
     */
    public function visit_Num(NumAST $node): void
    {
        // Push the number onto the stack
        $this->instructions[] = "PUSH {$node->value}";
    }
    
    /**
     * Visit a Statement node
     *
     * @param StatementAST $node The node to visit
     */
    public function visit_Statement(StatementAST $node): void
    {
        // Evaluate the expression but don't output
        $this->visit($node->expr);
        $this->instructions[] = 'POP';  // Just pop the result off the stack, no output
    }
    
    /**
     * Visit an EchoStatement node
     *
     * @param EchoStatementAST $node The node to visit
     */
    public function visit_EchoStatement(EchoStatementAST $node): void
    {
        $this->visit($node->expr);
        $this->instructions[] = 'PRINT';  // Output the result
    }
    
    /**
     * Visit a Compound node
     *
     * @param CompoundAST $node The node to visit
     */
    public function visit_Compound(CompoundAST $node): void
    {
        foreach ($node->statements as $statement) {
            $this->visit($statement);
        }
    }
    
    /**
     * Visit an IfStatement node
     *
     * @param IfStatementAST $node The node to visit
     */
    public function visit_IfStatement(IfStatementAST $node): void
    {
        // Generate unique labels for this if/else block
        $else_label = "ELSE_" . $this->label_counter;
        $end_label = "ENDIF_" . $this->label_counter;
        $this->label_counter++;
        
        // Evaluate the condition
        $this->visit($node->condition);
        
        // Jump to else block if condition is false (0)
        $this->instructions[] = "JZ {$else_label}";
        
        // If block
        $this->visit($node->if_body);
        // Jump to end after executing if block
        $this->instructions[] = "JMP {$end_label}";
        
        // Else block
        $this->instructions[] = "LABEL {$else_label}";
        if ($node->else_body !== null) {
            $this->visit($node->else_body);
        }
        
        // End of if/else statement
        $this->instructions[] = "LABEL {$end_label}";
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
        // Remove AST suffix from class name
        $method = str_replace('AST', '', $method);
        
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