<?php

namespace GazLang\CodeGenerator;

use Exception;
use GazLang\AST\AbstractNodeVisitor;
use GazLang\AST\ArrayLiteralAST;
use GazLang\AST\AssignAST;
use GazLang\AST\AST;
use GazLang\AST\BinOpAST;
use GazLang\AST\BooleanAST;
use GazLang\AST\CompoundAST;
use GazLang\AST\EchoStatementAST;
use GazLang\AST\ForeachStatementAST;
use GazLang\AST\FunctionCallAST;
use GazLang\AST\FunctionDeclarationAST;
use GazLang\AST\IfStatementAST;
use GazLang\AST\IncrementAST;
use GazLang\AST\IndexAST;
use GazLang\AST\LoopControlAST;
use GazLang\AST\NullAST;
use GazLang\AST\NumAST;
use GazLang\AST\ReturnStatementAST;
use GazLang\AST\StatementAST;
use GazLang\AST\StringAST;
use GazLang\AST\TryStatementAST;
use GazLang\AST\UnaryOpAST;
use GazLang\AST\VariableAST;
use GazLang\AST\WhileStatementAST;
use GazLang\Lexer\Lexer;
use GazLang\Lexer\Token;
use GazLang\Runtime\Builtins;

/**
 * CodeGenerator class transforms the AST into stack-based VM code
 *
 * Calling convention: the caller pushes arguments left to right and emits
 * CALL FN_name argc. The callee gets a fresh frame whose local slots 0..argc-1
 * hold the arguments, and RET pops the return value, drops the frame and pushes
 * the value onto the caller's stack. LOAD/STORE address the current frame's
 * locals; LOAD_GLOBAL/STORE_GLOBAL address the globals shared by every frame.
 * Function bodies are emitted after the top level code, which ends in HALT.
 *
 * Arrays are values. NEW_ARRAY pushes an empty array; ARRAY_PUSH pops a value
 * and appends it to the array below, ARRAY_SET pops a value and a key and sets
 * it. INDEX_GET pops an index and an array or string and pushes the element (or
 * null). For an indexed assignment the keys, then the value, then the variable's
 * current array are pushed, so the array is read after anything the keys and
 * value do to it. SET_PATH n pops the array, the value and n keys, and pushes the
 * value then the updated array; APPEND_PATH n does the same but appends after
 * following the n keys. The updated array is then stored back into the variable.
 *
 * Operators mean what Runtime\Values says: DIV keeps an exact int division an int
 * and gives a float otherwise, MOD is ints only, and PUSH writes floats exactly.
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
        Token::MODULO => 'MOD',
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
     * @var array<string, int> Local variable names mapped to slots in the current frame
     */
    private $var_addresses = [];

    /**
     * @var array<string, int> Global variable names mapped to global slots
     */
    private $global_addresses = [];

    /**
     * @var int Numbers the hidden variables of each lowered construct (foreach, +=, ++), so nested ones don't share them
     */
    private $hidden_counter = 0;

    /**
     * @var FunctionDeclarationAST[] Functions to emit after the top level code
     */
    private $functions = [];

    /**
     * @var int Counter for generating unique labels
     */
    private $label_counter;

    /**
     * @var array Stack of [continue label, end label, try depth] for the loops enclosing the current node
     */
    private $loop_labels = [];

    /**
     * @var int How many try blocks enclose the current node, so break and continue can leave them
     */
    private $try_depth = 0;

    /**
     * Constructor
     *
     * @param  object  $tree  The AST to generate code from
     */
    public function __construct(object $tree)
    {
        $this->tree = $tree;
        $this->instructions = [];
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
        $this->instructions[] = $this->variableInstruction('LOAD', $node);
    }

    /**
     * Visit an Assign node
     *
     * @param  AssignAST  $node  The node to visit
     */
    public function visitAssign(AssignAST $node): void
    {
        if ($node->token->type !== Token::ASSIGN) {
            $this->update($node->left, $node->token, $node->right, true);

            return;
        }

        if ($node->left instanceof IndexAST) {
            $this->indexAssign($node);

            return;
        }

        $store = $this->variableInstruction('STORE', $node->left);

        // Generate code for the right-hand side of the assignment
        $this->visit($node->right);

        // Store the computed value, then leave it on the stack for larger expressions
        $this->instructions[] = $store;
        $this->instructions[] = $this->variableInstruction('LOAD', $node->left);
    }

    /**
     * Visit an Increment node (++ or --)
     *
     * @param  IncrementAST  $node  The node to visit
     */
    public function visitIncrement(IncrementAST $node): void
    {
        $this->update($node->target, $node->op, null, $node->prefix);
    }

    /**
     * Emit a compound assignment or ++/--, lowered to plain assignments through hidden variables
     *
     * Index keys and the right side are evaluated once, in the interpreter's order (keys,
     * then the value, then read and write the target):
     *
     *   $a[k] += v  becomes  $#k0 = k; $#value = v; $a[$#k0] = $a[$#k0] + $#value
     *   $a[k]++     becomes  $#k0 = k; $#old = $a[$#k0]; $a[$#k0] = INC $#old    (leaving $#old)
     *
     * INC and DEC add or subtract one and fail on anything but a number, like Values::step().
     * The current value is read with INDEX_GET_EXISTING (Values::indexExisting()): the target
     * must be an array and the key must exist.
     *
     * @param  VariableAST|IndexAST  $target  The variable or element being updated
     * @param  Token  $op  The compound assignment, INCREMENT or DECREMENT token
     * @param  AST|null  $value  The right side of a compound assignment, null for ++ and --
     * @param  bool  $prefix  For ++ and --, whether to leave the new value rather than the old one
     */
    private function update(VariableAST|IndexAST $target, Token $op, ?AST $value, bool $prefix): void
    {
        $n = $this->hidden_counter++;
        $hidden = fn (string $name) => new VariableAST(new Token(Token::VAR_IDENTIFIER, "\$#update_{$name}_{$n}"));
        $assign = fn (VariableAST|IndexAST $to, AST $from) => new AssignAST($to, new Token(Token::ASSIGN, '='), $from);

        $indexes = [];
        for ($node = $target; $node instanceof IndexAST; $node = $node->target) {
            array_unshift($indexes, $node->index);
        }
        $place = $target instanceof IndexAST ? $target->rootVariable() : $target;
        foreach ($indexes as $i => $index) {
            $key = $hidden("key{$i}");
            $this->visit(new StatementAST($assign($key, $index)));
            $place = new IndexAST($place, $key);
            // The update reads the current value strictly, like the interpreter's store()
            $place->existing = true;
        }

        if ($value !== null) {
            $right = $hidden('value');
            $this->visit(new StatementAST($assign($right, $value)));
            [$type, $symbol] = [str_replace('_ASSIGN', '', $op->type), substr($op->value, 0, -1)];
            $this->visit($assign($place, new BinOpAST($place, new Token($type, $symbol), $right)));

            return;
        }

        $old = $hidden('old');
        $this->visit(new StatementAST($assign($old, $place)));
        $step = $assign($place, new UnaryOpAST($op, $old));
        if ($prefix) {
            $this->visit($step);
        } else {
            $this->visit(new StatementAST($step));
            $this->visit($old);
        }
    }

    /**
     * Emit an assignment through indexes, like $a["k"][0] = value or $a[] = value
     *
     * @param  AssignAST  $node  An assignment whose left side is an IndexAST rooted at a variable
     */
    private function indexAssign(AssignAST $node): void
    {
        $indexes = [];
        for ($target = $node->left; $target instanceof IndexAST; $target = $target->target) {
            array_unshift($indexes, $target->index);
        }
        $variable = $node->left->rootVariable();
        $append = $node->left->index === null;
        if ($append) {
            array_pop($indexes);
        }

        foreach ($indexes as $index) {
            $this->visit($index);
        }
        $this->visit($node->right);
        // Loaded last, like the interpreter, so side effects of the keys and value aren't overwritten
        $this->instructions[] = $this->variableInstruction('LOAD', $variable);

        $this->instructions[] = ($append ? 'APPEND_PATH ' : 'SET_PATH ').count($indexes);
        // Stores the updated array, leaving the assigned value on the stack
        $this->instructions[] = $this->variableInstruction('STORE', $variable);
    }

    /**
     * Build a LOAD/STORE instruction for a variable, allocating its slot the first time it is seen
     *
     * @param  string  $op  LOAD or STORE
     * @param  VariableAST  $variable  The variable
     * @return string e.g. "LOAD 0" for a local or "LOAD_GLOBAL 0" for a global
     */
    private function variableInstruction(string $op, VariableAST $variable): string
    {
        if ($variable->isGlobal()) {
            return "{$op}_GLOBAL ".($this->global_addresses[$variable->value] ??= count($this->global_addresses));
        }

        return "{$op} ".($this->var_addresses[$variable->value] ??= count($this->var_addresses));
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
        } elseif ($node->op->type === Token::INCREMENT || $node->op->type === Token::DECREMENT) {
            // Only produced by update(), for ++ and --
            $this->instructions[] = $node->op->type === Token::INCREMENT ? 'INC' : 'DEC';
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
        // Push the number onto the stack; floats are written so they read back exactly
        $this->instructions[] = 'PUSH '.(is_float($node->value) ? Lexer::format_float($node->value) : $node->value);
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
     * Visit a Null node
     *
     * @param  NullAST  $node  The node to visit
     */
    public function visitNull(NullAST $node): void
    {
        $this->instructions[] = 'PUSH null';
    }

    /**
     * Visit an ArrayLiteral node
     *
     * @param  ArrayLiteralAST  $node  The node to visit
     */
    public function visitArrayLiteral(ArrayLiteralAST $node): void
    {
        $this->instructions[] = 'NEW_ARRAY';
        foreach ($node->entries as [$key, $value]) {
            if ($key !== null) {
                $this->visit($key);
            }
            $this->visit($value);
            $this->instructions[] = $key === null ? 'ARRAY_PUSH' : 'ARRAY_SET';
        }
    }

    /**
     * Visit an Index node
     *
     * @param  IndexAST  $node  The node to visit
     */
    public function visitIndex(IndexAST $node): void
    {
        $this->visit($node->target);
        $this->visit($node->index);
        $this->instructions[] = $node->existing ? 'INDEX_GET_EXISTING' : 'INDEX_GET';
    }

    /**
     * Visit a String node
     *
     * @param  StringAST  $node  The node to visit
     */
    public function visitString(StringAST $node): void
    {
        $this->instructions[] = 'PUSH_STR '.Lexer::quote($node->value);
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

        // Jump to else block if condition is false
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
     * Visit a ForeachStatement node by emitting the equivalent while loop over keys()
     *
     * foreach ($array as $key => $value) { body } becomes, with hidden variables whose
     * names no program can write:
     *
     *   $#array = $array; $#keys = keys($#array); $#i = 0;
     *   while ($#i < len($#keys); step $#i = $#i + 1) { $key = $#keys[$#i]; $value = $#array[$#keys[$#i]]; body }
     *
     * The step runs on continue, as for for loops. A non-array fails in keys() rather
     * than with the interpreter's "foreach expects an array" message.
     *
     * @param  ForeachStatementAST  $node  The node to visit
     */
    public function visitForeachStatement(ForeachStatementAST $node): void
    {
        $n = $this->hidden_counter++;
        $hidden = fn (string $name) => new VariableAST(new Token(Token::VAR_IDENTIFIER, "\$#foreach_{$name}_{$n}"));
        $assign = fn (VariableAST $variable, AST $value) => new StatementAST(new AssignAST($variable, new Token(Token::ASSIGN, '='), $value));
        $array = $hidden('array');
        $keys = $hidden('keys');
        $i = $hidden('i');
        $key = new IndexAST($keys, $i);

        $body = new CompoundAST;
        if ($node->key !== null) {
            $body->statements[] = $assign($node->key, $key);
        }
        $body->statements[] = $assign($node->value, new IndexAST($array, $key));
        array_push($body->statements, ...$node->body->statements);

        $loop = new CompoundAST;
        $loop->statements = [
            $assign($array, $node->iterable),
            $assign($keys, new FunctionCallAST('keys', [$array])),
            $assign($i, new NumAST(new Token(Token::INTEGER, 0))),
            new WhileStatementAST(
                new BinOpAST($i, new Token(Token::LESS_THAN, '<'), new FunctionCallAST('len', [$keys])),
                $body,
                $assign($i, new BinOpAST($i, new Token(Token::PLUS, '+'), new NumAST(new Token(Token::INTEGER, 1))))
            ),
        ];

        $this->visit($loop);
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

        $this->loop_labels[] = [$continue_label, $end_label, $this->try_depth];
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
     * Visit a TryStatement node
     *
     *   TRY CATCH_n        installs a handler for errors raised until END_TRY
     *   body
     *   END_TRY            removes it
     *   JMP ENDTRY_n
     *   LABEL CATCH_n      an error unwinds to the frame and stack depth of the TRY,
     *   STORE error_var    pushes ["message" => ..., "file" => ..., "line" => ...] and jumps here
     *   catch body
     *   LABEL ENDTRY_n
     *
     * break and continue emit END_TRY for each try they leave; RET removes the handlers
     * installed by the returning function's frame.
     *
     * @param  TryStatementAST  $node  The node to visit
     */
    public function visitTryStatement(TryStatementAST $node): void
    {
        $catch_label = 'CATCH_'.$this->label_counter;
        $end_label = 'ENDTRY_'.$this->label_counter;
        $this->label_counter++;

        $this->instructions[] = "TRY {$catch_label}";
        $this->try_depth++;
        $this->visit($node->body);
        $this->try_depth--;
        $this->instructions[] = 'END_TRY';
        $this->instructions[] = "JMP {$end_label}";

        $this->instructions[] = "LABEL {$catch_label}";
        $this->instructions[] = $this->variableInstruction('STORE', $node->variable);
        $this->visit($node->catch_body);
        $this->instructions[] = "LABEL {$end_label}";
    }

    /**
     * Visit a LoopControl node, jumping to the innermost loop's continue or end label
     *
     * @param  LoopControlAST  $node  The node to visit
     */
    public function visitLoopControl(LoopControlAST $node): void
    {
        if ($this->loop_labels === []) {
            throw new Exception("Cannot use {$node->token->value} outside of a loop");
        }

        [$continue_label, $end_label, $loop_try_depth] = end($this->loop_labels);
        $label = $node->token->type === Token::BREAK ? $end_label : $continue_label;

        // Jumping out of a try block leaves it, so its handler must be removed first
        for ($depth = $this->try_depth; $depth > $loop_try_depth; $depth--) {
            $this->instructions[] = 'END_TRY';
        }
        $this->instructions[] = "JMP {$label}";
    }

    /**
     * Visit a FunctionDeclaration node, deferring its body until after the top level code
     *
     * @param  FunctionDeclarationAST  $node  The node to visit
     */
    public function visitFunctionDeclaration(FunctionDeclarationAST $node): void
    {
        $this->functions[] = $node;
    }

    /**
     * Visit a FunctionCall node
     *
     * @param  FunctionCallAST  $node  The node to visit
     */
    public function visitFunctionCall(FunctionCallAST $node): void
    {
        foreach ($node->args as $arg) {
            $this->visit($arg);
        }

        $this->instructions[] = isset(Builtins::ARITIES[$node->name])
            ? "CALL_BUILTIN {$node->name} ".count($node->args)
            : "CALL FN_{$node->name} ".count($node->args);
    }

    /**
     * Visit a ReturnStatement node
     *
     * @param  ReturnStatementAST  $node  The node to visit
     */
    public function visitReturnStatement(ReturnStatementAST $node): void
    {
        if ($node->expr === null) {
            $this->instructions[] = 'PUSH null';
        } else {
            $this->visit($node->expr);
        }

        $this->instructions[] = 'RET';
    }

    /**
     * Emit a function's prologue for its default parameter values
     *
     * ARGC pushes how many arguments the caller passed. For each parameter with a default,
     * if fewer than its position + 1 were passed, the default is evaluated and stored in the
     * parameter's slot, so later defaults can use it.
     *
     * @param  FunctionDeclarationAST  $function  The function
     */
    private function defaultArguments(FunctionDeclarationAST $function): void
    {
        foreach ($function->defaults as $i => $default) {
            if ($default === null) {
                continue;
            }
            $skip_label = 'PASSED_'.$this->label_counter++;

            $this->instructions[] = 'ARGC';
            $this->instructions[] = "PUSH {$i}";
            $this->instructions[] = 'GT';
            $this->instructions[] = 'NOT';
            $this->instructions[] = "JZ {$skip_label}";
            $this->visit($default);
            $this->instructions[] = "STORE {$i}";
            $this->instructions[] = "LABEL {$skip_label}";
        }
    }

    /**
     * Generate code from the AST
     *
     * @return string The generated code
     */
    public function generate(): string
    {
        $this->visit($this->tree);

        if ($this->functions !== []) {
            $this->instructions[] = 'HALT';
        }

        foreach ($this->functions as $function) {
            // Each function has its own frame, with the arguments in the first slots
            $this->var_addresses = array_flip($function->params);

            $this->instructions[] = "LABEL FN_{$function->name}";
            $this->defaultArguments($function);
            $this->visit($function->body);
            // Falling off the end returns null
            $this->instructions[] = 'PUSH null';
            $this->instructions[] = 'RET';
        }

        return implode("\n", $this->instructions);
    }
}
