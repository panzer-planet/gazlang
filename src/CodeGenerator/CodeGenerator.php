<?php

namespace GazLang\CodeGenerator;

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

/**
 * CodeGenerator class transforms the AST into stack-based VM code
 */
class CodeGenerator extends AbstractNodeVisitor
{
    /**
     * @var object The AST to generate code from
     */
    private object $tree;

    /**
     * @var array The generated instructions
     */
    private array $instructions;

    /**
     * @var array Map of variable names to memory addresses
     */
    private array $var_addresses;

    /**
     * @var int Next available memory address for variable storage
     */
    private int $next_address;

    /**
     * @var int Counter for generating unique labels
     */
    private int $label_counter;

    /**
     * Constructor
     *
     * @param  object  $tree  The AST to generate code from
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
     * @param  VariableAST  $node  The node to visit
     */
    public function visitVariable(VariableAST $node): void
    {
        $var_name = $node->value;
        if (! isset($this->var_addresses[$var_name])) {
            throw new Exception("Undefined variable: {$var_name}");
        }

        // Load the variable's value onto the stack
        $this->instructions[] = "LOAD {$this->var_addresses[$var_name]}";
    }

    /**
     * Visit an Assign node
     *
     * @param  AssignAST  $node  The node to visit
     */
    public function visitAssign(AssignAST $node): void
    {
        $var_name = $node->left->value;

        // Allocate memory for the variable if not already allocated
        if (! isset($this->var_addresses[$var_name])) {
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
     * @param  BinOpAST  $node  The node to visit
     */
    public function visitBinOp(BinOpAST $node): void
    {
        // Special handling for short-circuit logical operators
        if ($node->op->type === Token::AND) {
            // Generate unique labels for short-circuit evaluation
            $short_circuit_label = 'SC_AND_'.$this->label_counter;
            $end_label = 'END_AND_'.$this->label_counter;
            $this->label_counter++;

            // Evaluate left operand
            $this->visit($node->left);

            // Convert to boolean if necessary
            $this->instructions[] = 'TO_BOOL';

            // Duplicate top of stack for condition check
            $this->instructions[] = 'DUP';

            // If left operand is false, short-circuit (leave false on stack)
            $this->instructions[] = "JZ {$short_circuit_label}";

            // Left operand was true, pop the duplicate and evaluate right operand
            $this->instructions[] = 'POP';
            $this->visit($node->right);

            // Convert right operand to boolean
            $this->instructions[] = 'TO_BOOL';

            // Jump to end
            $this->instructions[] = "JMP {$end_label}";

            // Short-circuit label (false is already on stack from DUP)
            $this->instructions[] = "LABEL {$short_circuit_label}";

            // End label
            $this->instructions[] = "LABEL {$end_label}";

            return;
        } elseif ($node->op->type === Token::OR) {
            // Generate unique labels for short-circuit evaluation
            $short_circuit_label = 'SC_OR_'.$this->label_counter;
            $end_label = 'END_OR_'.$this->label_counter;
            $this->label_counter++;

            // Evaluate left operand
            $this->visit($node->left);

            // Convert to boolean if necessary
            $this->instructions[] = 'TO_BOOL';

            // Duplicate top of stack for condition check
            $this->instructions[] = 'DUP';

            // If left operand is true, short-circuit (leave true on stack)
            $this->instructions[] = "JNZ {$short_circuit_label}";

            // Left operand was false, pop the duplicate and evaluate right operand
            $this->instructions[] = 'POP';
            $this->visit($node->right);

            // Convert right operand to boolean
            $this->instructions[] = 'TO_BOOL';

            // Jump to end
            $this->instructions[] = "JMP {$end_label}";

            // Short-circuit label (true is already on stack from DUP)
            $this->instructions[] = "LABEL {$short_circuit_label}";

            // End label
            $this->instructions[] = "LABEL {$end_label}";

            return;
        }

        // For other operators, evaluate both operands first (post-order traversal)
        $this->visit($node->left);
        $this->visit($node->right);

        // Now emit the operation instruction
        if ($node->op->type === Token::PLUS) {
            // For plus, we need to handle possible string concatenation
            $this->instructions[] = 'ADD_OR_CONCAT';
        } elseif ($node->op->type === Token::MINUS) {
            $this->instructions[] = 'SUB';
        } elseif ($node->op->type === Token::MULTIPLY) {
            $this->instructions[] = 'MUL';
        } elseif ($node->op->type === Token::DIVIDE) {
            $this->instructions[] = 'DIV';
        } elseif ($node->op->type === Token::EQUALS) {
            $this->instructions[] = 'EQUALS';
        } else if ($node->op->type === Token::NOT_EQUALS) {
            $this->instructions[] = 'NOT_EQUALS';
        } elseif ($node->op->type === Token::AND) {
            $this->instructions[] = 'AND';
        } elseif ($node->op->type === Token::OR) {
            $this->instructions[] = 'OR';

        } else {
            throw new Exception("Unknown operator: {$node->op->type}");
        }
    }

    /**
     * Visit a Num node
     *
     * @param  NumAST  $node  The node to visit
     */
    public function visitNum(NumAST $node): void
    {
        // Push the number onto the stack
        $this->instructions[] = "PUSH {$node->value}";
    }

    /**
     * Visit a String node
     *
     * @param  StringAST  $node  The node to visit
     */
    public function visitString(StringAST $node): void
    {
        // Escape special characters in the string for the code representation
        $escapedValue = addcslashes($node->value, "\"\n\r\t\\");

        // Push the string onto the stack
        $this->instructions[] = "PUSH_STR \"{$escapedValue}\"";
    }

    /**
     * Visit a Bool node
     *
     * @param  BoolAST  $node  The node to visit
     */
    public function visitBool(BoolAST $node): void
    {
        // Push the boolean value onto the stack (as 1 for true, 0 for false)
        $value = $node->value ? 1 : 0;
        $this->instructions[] = "PUSH {$value}";
    }

    /**
     * Visit a UnaryOp node
     *
     * @param  UnaryOpAST  $node  The node to visit
     */
    public function visitUnaryOp(UnaryOpAST $node): void
    {
        // Evaluate the operand first
        $this->visit($node->expr);

        if ($node->op->type === Token::NOT) {
            // Convert to boolean if necessary
            $this->instructions[] = 'TO_BOOL';

            // Logical NOT operation
            $this->instructions[] = 'NOT';
        } else {
            throw new Exception("Unknown unary operator: {$node->op->type}");
        }
    }

    /**
     * Visit a Statement node
     *
     * @param  StatementAST  $node  The node to visit
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
     * @param  EchoStatementAST  $node  The node to visit
     */
    public function visitEchoStatement(EchoStatementAST $node): void
    {
        $this->visit($node->expr);
        $this->instructions[] = 'PRINT';  // Output the result
    }

    /**
     * Visit a Compound node
     *
     * @param  CompoundAST  $node  The node to visit
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
     * @param  IfStatementAST  $node  The node to visit
     */
    public function visitIfStatement(IfStatementAST $node): void
    {
        // Generate unique labels for this if/else block
        $else_label = 'ELSE_'.$this->label_counter;
        $end_label = 'ENDIF_'.$this->label_counter;
        $this->label_counter++;

        // Evaluate the condition
        $this->visit($node->condition);

        // Convert to boolean if necessary
        $this->instructions[] = 'TO_BOOL';

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
        } elseif ($node->else_body !== null) {
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
