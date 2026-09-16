<?php

namespace GazLang\CodeGenerator;

use Exception;
use GazLang\AST\AbstractNodeVisitor;
use GazLang\AST\AssignAST;
use GazLang\AST\BinOpAST;
use GazLang\AST\BooleanAST;
use GazLang\AST\CompoundAST;
use GazLang\AST\EchoStatementAST;
use GazLang\AST\IfStatementAST;
use GazLang\AST\LoopControlAST;
use GazLang\AST\NumAST;
use GazLang\AST\StatementAST;
use GazLang\AST\StringAST;
use GazLang\AST\UnaryOpAST;
use GazLang\AST\VariableAST;
use GazLang\AST\WhileStatementAST;
use GazLang\Lexer\Token;

/**
 * CodeGenerator class transforms the AST into stack-based VM code
 */
class CodeGenerator extends AbstractNodeVisitor
{
    /**
     * Opcode emitted for each binary operator token (&& and || are jumps, see logicalOp)
     */
    private const BINARY_OPCODES = [
        Token::PLUS => 'ADD_OR_CONCAT',
        Token::MINUS => 'SUB',
        Token::MULTIPLY => 'MUL',
        Token::DIVIDE => 'DIV',
        Token::EQUALS => 'EQUALS',
        Token::NOT_EQUALS => 'NOT_EQUALS',
        Token::STRICT_EQUALS => 'STRICT_EQUALS',
        Token::STRICT_NOT_EQUALS => 'STRICT_NOT_EQUALS',
        Token::LESS_THAN => 'LT',
        Token::LESS_EQUALS => 'LE',
        Token::GREATER_THAN => 'GT',
        Token::GREATER_EQUALS => 'GE',
    ];

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
     * @var array Stack of [continue label, end label] for the loops enclosing the current node
     */
    private $loop_labels = [];

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
        // Undefined variables are a runtime error: a loop can read a variable
        // that is only assigned further down the source
        $this->instructions[] = 'LOAD '.$this->address($node->value);
    }

    /**
     * Visit an Assign node
     *
     * @param  AssignAST  $node  The node to visit
     */
    public function visitAssign(AssignAST $node): void
    {
        $address = $this->address($node->left->value);

        // Generate code for the right-hand side of the assignment
        $this->visit($node->right);

        // Store the computed value, then leave it on the stack for larger expressions
        $this->instructions[] = "STORE {$address}";
        $this->instructions[] = "LOAD {$address}";
    }

    /**
     * Get a variable's memory address, allocating one the first time it is seen
     *
     * @param  string  $var_name  The variable name, including the $
     */
    private function address(string $var_name): int
    {
        return $this->var_addresses[$var_name] ??= $this->next_address++;
    }

    /**
     * Visit a BinOp node
     *
     * @param  BinOpAST  $node  The node to visit
     */
    public function visitBinOp(BinOpAST $node): void
    {
        if ($node->op->type === Token::AND || $node->op->type === Token::OR) {
            $this->logicalOp($node);

            return;
        }

        // Visit left and right nodes first (post-order traversal)
        $this->visit($node->left);
        $this->visit($node->right);

        if (! isset(self::BINARY_OPCODES[$node->op->type])) {
            throw new Exception("Unknown operator: {$node->op->type}");
        }

        $this->instructions[] = self::BINARY_OPCODES[$node->op->type];
    }

    /**
     * Emit short-circuit code for && and ||, leaving true or false on the stack
     *
     * For &&, any false operand jumps to push false. For ||, each operand is
     * negated first, so any true operand jumps to push true.
     *
     * @param  BinOpAST  $node  The && or || node
     */
    private function logicalOp(BinOpAST $node): void
    {
        $is_and = $node->op->type === Token::AND;
        $short_label = ($is_and ? 'AND_FALSE_' : 'OR_TRUE_').$this->label_counter;
        $end_label = ($is_and ? 'AND_END_' : 'OR_END_').$this->label_counter;
        $this->label_counter++;

        foreach ([$node->left, $node->right] as $operand) {
            $this->visit($operand);
            if (! $is_and) {
                $this->instructions[] = 'NOT';
            }
            $this->instructions[] = "JZ {$short_label}";
        }

        $this->instructions[] = $is_and ? 'PUSH true' : 'PUSH false';
        $this->instructions[] = "JMP {$end_label}";
        $this->instructions[] = "LABEL {$short_label}";
        $this->instructions[] = $is_and ? 'PUSH false' : 'PUSH true';
        $this->instructions[] = "LABEL {$end_label}";
    }

    /**
     * Visit a UnaryOp node
     *
     * @param  UnaryOpAST  $node  The node to visit
     */
    public function visitUnaryOp(UnaryOpAST $node): void
    {
        $this->visit($node->expr);

        if ($node->op->type === Token::NOT) {
            $this->instructions[] = 'NOT';
        } elseif ($node->op->type === Token::MINUS) {
            $this->instructions[] = 'NEG';
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
     * Visit a Boolean node
     *
     * @param  BooleanAST  $node  The node to visit
     */
    public function visitBoolean(BooleanAST $node): void
    {
        $this->instructions[] = $node->value ? 'PUSH true' : 'PUSH false';
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

    /**
     * Visit a WhileStatement node
     *
     * @param  WhileStatementAST  $node  The node to visit
     */
    public function visitWhileStatement(WhileStatementAST $node): void
    {
        $start_label = 'WHILE_'.$this->label_counter;
        $end_label = 'ENDWHILE_'.$this->label_counter;
        // continue has to run the step, so a loop with one jumps to just before it
        $continue_label = $node->step !== null ? 'CONTINUE_'.$this->label_counter : $start_label;
        $this->label_counter++;

        $this->instructions[] = "LABEL {$start_label}";
        $this->visit($node->condition);
        $this->instructions[] = "JZ {$end_label}";

        $this->loop_labels[] = [$continue_label, $end_label];
        $this->visit($node->body);
        array_pop($this->loop_labels);

        if ($node->step !== null) {
            $this->instructions[] = "LABEL {$continue_label}";
            $this->visit($node->step);
        }

        $this->instructions[] = "JMP {$start_label}";
        $this->instructions[] = "LABEL {$end_label}";
    }

    /**
     * Visit a LoopControl node, jumping to the innermost loop's continue or end label
     *
     * @param  LoopControlAST  $node  The node to visit
     */
    public function visitLoopControl(LoopControlAST $node): void
    {
        [$continue_label, $end_label] = end($this->loop_labels);
        $label = $node->token->type === Token::BREAK ? $end_label : $continue_label;

        $this->instructions[] = "JMP {$label}";
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
