<?php

namespace GazLang\CodeGenerator;

use Exception;
use GazLang\AST\AbstractNodeVisitor;
use GazLang\AST\BinOpAST;
use GazLang\AST\CompoundAST;
use GazLang\AST\NumAST;
use GazLang\AST\StringAST;
use GazLang\AST\StatementAST;
use GazLang\AST\EchoStatementAST;
use GazLang\AST\IfStatementAST;
use GazLang\AST\VariableAST;
use GazLang\AST\AssignAST;
use GazLang\Lexer\Token;

/**
 * CodeGenerator class transforms the AST into stack-based VM code
 */
class CodeGenerator extends AbstractNodeVisitor
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
     * @return void
     */
    public function visitVariable(VariableAST $node): void
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
     * @return void
     */
    public function visitAssign(AssignAST $node): void
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
     * @return void
     */
    public function visitBinOp(BinOpAST $node): void
    {
        // Visit left and right nodes first (post-order traversal)
        $this->visit($node->left);
        $this->visit($node->right);
        
        // Now emit the operation instruction
        if ($node->op->type === Token::PLUS) {
            // For plus, we need to handle possible string concatenation
            $this->instructions[] = 'ADD_OR_CONCAT';
        } else if ($node->op->type === Token::MINUS) {
            $this->instructions[] = 'SUB';
        } else if ($node->op->type === Token::MULTIPLY) {
            $this->instructions[] = 'MUL';
        } else if ($node->op->type === Token::DIVIDE) {
            $this->instructions[] = 'DIV';
        } else if ($node->op->type === Token::EQUALS) {
            $this->instructions[] = 'EQUALS';
        } else {
            throw new Exception("Unknown operator: {$node->op->type}");
        }
    }
    
    /**
     * Visit a Num node
     *
     * @param NumAST $node The node to visit
     * @return void
     */
    public function visitNum(NumAST $node): void
    {
        // Push the number onto the stack
        $this->instructions[] = "PUSH {$node->value}";
    }
    
    /**
     * Visit a String node
     *
     * @param StringAST $node The node to visit
     * @return void
     */
    public function visitString(StringAST $node): void
    {
        // Escape special characters in the string for the code representation
        $escapedValue = addcslashes($node->value, "\"\n\r\t\\");
        
        // Push the string onto the stack
        $this->instructions[] = "PUSH_STR \"{$escapedValue}\"";
    }
    
    /**
     * Visit a Statement node
     *
     * @param StatementAST $node The node to visit
     * @return void
     */
    public function visitStatement(StatementAST $node): void
    {
        // Evaluate the expression but don't output
        $this->visit($node->expr);
        $this->instructions[] = 'POP';  // Just pop the result off the stack, no output
    }
    
    /**
     * Visit an EchoStatement node
     *
     * @param EchoStatementAST $node The node to visit
     * @return void
     */
    public function visitEchoStatement(EchoStatementAST $node): void
    {
        $this->visit($node->expr);
        $this->instructions[] = 'PRINT';  // Output the result
    }
    
    /**
     * Visit a Compound node
     *
     * @param CompoundAST $node The node to visit
     * @return void
     */
    public function visitCompound(CompoundAST $node): void
    {
        foreach ($node->statements as $statement) {
            $this->visit($statement);
        }
    }
    
    /**
     * Visit an IfStatement node
     *
     * @param IfStatementAST $node The node to visit
     * @return void
     */
    public function visitIfStatement(IfStatementAST $node): void
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
        
        // Else or else-if block
        $this->instructions[] = "LABEL {$else_label}";
        
        if ($node->else_if !== null) {
            // Handle else-if branch
            $this->visit($node->else_if);
        } else if ($node->else_body !== null) {
            // Handle else branch
            $this->visit($node->else_body);
        }
        
        // End of if/else statement
        $this->instructions[] = "LABEL {$end_label}";
    }
    
    // The visit method is now implemented in AbstractNodeVisitor
    
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