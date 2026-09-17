<?php

namespace GazLang\CodeGenerator;

use Exception;
use GazLang\AST\AbstractNodeVisitor;
use GazLang\AST\ArrayLiteralAST;
use GazLang\AST\AssignAST;
use GazLang\AST\AST;
use GazLang\AST\BinOpAST;
use GazLang\AST\BooleanAST;
use GazLang\AST\CallValueAST;
use GazLang\AST\CompoundAST;
use GazLang\AST\EchoStatementAST;
use GazLang\AST\ForeachStatementAST;
use GazLang\AST\FunctionCallAST;
use GazLang\AST\FunctionDeclarationAST;
use GazLang\AST\FunctionRefAST;
use GazLang\AST\IfStatementAST;
use GazLang\AST\IncrementAST;
use GazLang\AST\IndexAST;
use GazLang\AST\LambdaAST;
use GazLang\AST\LoopControlAST;
use GazLang\AST\NullAST;
use GazLang\AST\NumAST;
use GazLang\AST\ReturnStatementAST;
use GazLang\AST\StatementAST;
use GazLang\AST\StringAST;
use GazLang\AST\TernaryAST;
use GazLang\AST\TryStatementAST;
use GazLang\AST\UnaryOpAST;
use GazLang\AST\VariableAST;
use GazLang\AST\WhileStatementAST;
use GazLang\Lexer\Token;
use GazLang\Runtime\Builtins;
use GazLang\Runtime\MapValue;

/**
 * CodeGenerator class transforms the AST into stack-based VM code
 *
 * Calling convention: the caller pushes arguments left to right and emits
 * CALL FN_name argc. The callee gets a fresh frame whose local slots 0..argc-1
 * hold the arguments, and RET pops the return value, drops the frame and pushes
 * the value onto the caller's stack. LOAD/STORE address the current frame's
 * locals; LOAD_GLOBAL/STORE_GLOBAL address the globals shared by every frame.
 * Function bodies are emitted after the top level code, which ends in HALT.
 * PUSH_FN name pushes a function as a value, and CALL_VALUE argc pops argc
 * arguments and the value under them and calls it, checking at runtime that it is a
 * function taking that many arguments (the Program's function table gives each
 * function's arity).
 *
 * Lambda bodies are emitted after the functions, each under LABEL LAMBDA_n with its own
 * frame: parameters in slots 0.., then its other locals. Its captured variables live in
 * the closure and are addressed by index with LOAD_CAPTURED, STORE_CAPTURED,
 * LOAD_QUIET_CAPTURED and SET_PATH_CAPTURED/APPEND_PATH_CAPTURED. MAKE_CLOSURE n pushes a
 * closure, copying into it what the Program's lambda table maps from the enclosing frame
 * or the enclosing closure (only what exists, as the interpreter does), then storing the
 * closure in its own $self variable; CALL_VALUE on a closure starts a frame from the
 * arguments, with that closure running, and jumps to the lambda's entry.
 *
 * Lists and maps are values. NEW_ARRAY pushes an empty list and ARRAY_PUSH pops a value
 * and appends it to the list below; NEW_MAP pushes an empty map and MAP_SET pops a value
 * and a key and sets it in the map below. KEY_CHECK fails unless the value on top of the
 * stack can be a key (an int or string), and is emitted right after each key expression,
 * so a bad key fails before later keys and the value run, as in the interpreter.
 * INDEX_GET pops an index and a list, map or string and pushes the element. For an indexed assignment the keys, then the value are pushed, and
 * SET_PATH n slot pops the value and n keys, sets the element of the variable in
 * that local slot in place (SET_PATH_GLOBAL for a global), and pushes the value;
 * APPEND_PATH n slot appends after following the n keys. The variable is read
 * after the keys and value run, as in the interpreter.
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
        Token::PLUS => 'ADD',
        Token::CONCAT => 'CONCAT',
        Token::MINUS => 'SUB',
        Token::MULTIPLY => 'MUL',
        Token::DIVIDE => 'DIV',
        Token::MODULO => 'MOD',
        Token::EQUALS => 'EQUALS',
        Token::NOT_EQUALS => 'NOT_EQUALS',
        Token::LESS_THAN => 'LT',
        Token::LESS_EQUALS => 'LE',
        Token::SPACESHIP => 'CMP',
        Token::GREATER_THAN => 'GT',
        Token::GREATER_EQUALS => 'GE',
    ];

    /**
     * @var object The AST to generate code from
     */
    private $tree;

    /**
     * @var list<array{0: string, 1: array, 2: string|null, 3: int|null}> The generated instructions, see Program
     */
    private $instructions;

    /**
     * @var string|null The file of the innermost node being compiled, recorded on each instruction
     */
    private $file;

    /**
     * @var int|null The line of the innermost node being compiled
     */
    private $line;

    /**
     * @var array<string, int> Local variable names mapped to slots in the current frame
     */
    private $var_addresses = [];

    /**
     * @var array<string, int> When compiling a lambda body, its captured variable names mapped to their
     *                         index in the closure, which *_CAPTURED instructions address instead of a slot
     */
    private $captures = [];

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
     * @var list<array{0: LambdaAST, 1: list<array{0: bool, 1: int, 2: int}>}> Every lambda seen, in creation order,
     *                                                                         with its capture map: [whether the source is a captured
     *                                                                         variable of the enclosing closure, its slot or index there,
     *                                                                         the index in the new closure]; bodies compile later
     */
    private $lambdas = [];

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
     * Visit a node, recording its location on the instructions emitted for it
     *
     * The location is the innermost node that has one, the same node whose location the
     * interpreter reports for an error, so both backends' errors point at the same place.
     * Nodes the code generator builds itself (for lowered constructs) have none, and keep
     * the location of the node they were built for.
     *
     * @param  object  $node  The node to visit
     * @return mixed The result of visiting the node
     */
    public function visit(object $node)
    {
        if ($node->line === null) {
            return parent::visit($node);
        }

        [$file, $line] = [$this->file, $this->line];
        [$this->file, $this->line] = [$node->file, $node->line];
        try {
            return parent::visit($node);
        } finally {
            [$this->file, $this->line] = [$file, $line];
        }
    }

    /**
     * Append an instruction at the current location
     *
     * @param  string  $opcode  The opcode
     * @param  mixed  ...$args  Its arguments
     */
    private function emit(string $opcode, ...$args): void
    {
        $this->instructions[] = [$opcode, $args, $this->file, $this->line];
    }

    /**
     * Emit a variable instruction, allocating the variable's slot the first time it is seen
     *
     * @param  string  $op  LOAD, STORE, SET_PATH or APPEND_PATH; globals get the _GLOBAL form
     * @param  VariableAST  $variable  The variable
     * @param  mixed  ...$args  Arguments before the slot (the key count for the path instructions)
     */
    private function emitVariable(string $op, VariableAST $variable, ...$args): void
    {
        if ($variable->isGlobal()) {
            $this->emit("{$op}_GLOBAL", ...$args, ...[$this->global_addresses[$variable->value] ??= count($this->global_addresses)]);

            return;
        }

        if (isset($this->captures[$variable->value])) {
            $this->emit("{$op}_CAPTURED", ...$args, ...[$this->captures[$variable->value]]);

            return;
        }

        $this->emit($op, ...$args, ...[$this->var_addresses[$variable->value] ??= count($this->var_addresses)]);
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
        $this->emitVariable('LOAD', $node);
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

        // Generate code for the right-hand side of the assignment
        $this->visit($node->right);

        // Store the computed value, then leave it on the stack for larger expressions
        $this->emitVariable('STORE', $node->left);
        $this->emitVariable('LOAD', $node->left);
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
     * Emit a compound assignment or ++/--, lowered to plain assignments
     *
     * Index keys and the right side are evaluated once, in the interpreter's order (keys,
     * then the value, then read and write the target), with keys and a non-constant
     * right side held in hidden variables:
     *
     *   $a[k] += v  becomes  $#k0 = k; $#value = v; $a[$#k0] = $a[$#k0] + $#value
     *   $x += 1     becomes  $x = $x + 1          (a constant can't fail or change $x)
     *   ++$a[k]     becomes  $#k0 = k; $a[$#k0] = INC $a[$#k0]
     *   $a[k]++     becomes  $#k0 = k; $a[$#k0]; $a[$#k0] = INC $a[$#k0];   (the first read is the result)
     *   $a[k] ??= v becomes  $#k0 = k; $a[$#k0] ?? ($a[$#k0] = v)
     *
     * Reading the target twice for postfix is safe: the keys are already evaluated and
     * nothing runs in between. A postfix ++ used as a statement is emitted as prefix (see
     * visitStatement()), so $i++ in a loop is LOAD, INC, STORE.
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
            $this->visit($index);
            $this->emit('KEY_CHECK');
            $this->emitVariable('STORE', $key);
            $place = new IndexAST($place, $key);
            // The update reads the current value strictly, like the interpreter's store(),
            // except ??=, which reads it like ?? does
            $place->existing = $op->type !== Token::COALESCE_ASSIGN;
        }

        if ($op->type === Token::COALESCE_ASSIGN) {
            // $a ??= $b is $a ?? ($a = $b), with the keys already in hidden variables
            $end_label = 'COALESCE_END_'.$this->label_counter++;
            $this->quietly($place);
            $this->emit('JNN', $end_label);
            $this->visit($assign($place, $value));
            $this->emit('LABEL', $end_label);

            return;
        }

        if ($value !== null) {
            $right = $value;
            if (! self::isConstant($value)) {
                $right = $hidden('value');
                $this->visit(new StatementAST($assign($right, $value)));
            }
            [$type, $symbol] = [str_replace('_ASSIGN', '', $op->type), substr($op->value, 0, -1)];
            $this->visit($assign($place, new BinOpAST($place, new Token($type, $symbol), $right)));

            return;
        }

        $step = $assign($place, new UnaryOpAST($op, $place));
        if ($prefix) {
            $this->visit($step);
        } else {
            $this->visit($place);
            $this->visit(new StatementAST($step));
        }
    }

    /**
     * Whether a node is a literal whose evaluation can't fail or have side effects
     *
     * @param  AST  $node  The node
     *
     * @phpstan-assert-if-true NumAST|StringAST|BooleanAST|NullAST $node
     */
    private static function isConstant(AST $node): bool
    {
        return $node instanceof NumAST || $node instanceof StringAST || $node instanceof BooleanAST || $node instanceof NullAST;
    }

    /**
     * The value of a list or map literal made only of constants with valid keys, or null if it isn't one
     *
     * Built exactly as the interpreter's visitArrayLiteral() would build it, so it can be
     * pushed as one finished value. Lists and maps are values (Values::store() clones a map
     * before changing it), so sharing it is safe.
     *
     * @param  ArrayLiteralAST  $node  The literal
     */
    private static function constantArray(ArrayLiteralAST $node): array|MapValue|null
    {
        $array = [];
        foreach ($node->entries as [$key, $value]) {
            if ($key !== null && ! (($key instanceof NumAST && is_int($key->value)) || $key instanceof StringAST)) {
                return null;
            }
            if ($value instanceof ArrayLiteralAST) {
                $value = self::constantArray($value);
                if ($value === null) {
                    return null;
                }
            } elseif (self::isConstant($value)) {
                $value = $value->value;
            } else {
                return null;
            }

            if ($key === null) {
                $array[] = $value;
            } else {
                $array[MapValue::key($key->value)] = $value;
            }
        }

        return $node->map ? new MapValue($array) : $array;
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
            // A hidden $# variable holds a key update() already checked
            if (! ($index instanceof VariableAST && str_starts_with($index->value, '$#'))) {
                $this->emit('KEY_CHECK');
            }
        }
        $this->visit($node->right);

        // The variable is only read now, like the interpreter, so side effects of the keys and
        // value aren't overwritten; and it is updated in place, so appending stays linear
        $this->emitVariable($append ? 'APPEND_PATH' : 'SET_PATH', $variable, count($indexes));
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

        if ($node->op->type === Token::COALESCE) {
            // JNN keeps the left value and jumps past the right side unless it is null
            $end_label = 'COALESCE_END_'.$this->label_counter++;
            $this->quietly($node->left);
            $this->emit('JNN', $end_label);
            $this->visit($node->right);
            $this->emit('LABEL', $end_label);

            return;
        }

        // Visit left and right nodes first (post-order traversal)
        $this->visit($node->left);
        $this->visit($node->right);

        if (! isset(self::BINARY_OPCODES[$node->op->type])) {
            throw new Exception("Unknown operator: {$node->op->type}");
        }

        $this->emit(self::BINARY_OPCODES[$node->op->type]);
    }

    /**
     * Emit the left side of ??, where a missing variable or key is null (see Interpreter::quietly())
     *
     * LOAD_QUIET pushes null for an undefined variable; INDEX_GET_QUIET pushes null when
     * the target is null, and otherwise reads like INDEX_GET.
     *
     * @param  AST  $node  The left side
     */
    private function quietly(AST $node): void
    {
        if ($node instanceof VariableAST) {
            $this->emitVariable('LOAD_QUIET', $node);
        } elseif ($node instanceof IndexAST && $node->index !== null) {
            $this->quietly($node->target);
            $this->visit($node->index);
            $this->emit('INDEX_GET_QUIET');
        } else {
            $this->visit($node);
        }
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
                $this->emit('NOT');
            }
            $this->emit('JZ', $short_label);
        }

        $this->emit('PUSH', $is_and);
        $this->emit('JMP', $end_label);
        $this->emit('LABEL', $short_label);
        $this->emit('PUSH', ! $is_and);
        $this->emit('LABEL', $end_label);
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
            $this->emit('NOT');
        } elseif ($node->op->type === Token::MINUS) {
            $this->emit('NEG');
        } elseif ($node->op->type === Token::INCREMENT || $node->op->type === Token::DECREMENT) {
            // Only produced by update(), for ++ and --
            $this->emit($node->op->type === Token::INCREMENT ? 'INC' : 'DEC');
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
        $this->emit('PUSH', $node->value);
    }

    /**
     * Visit a Boolean node
     *
     * @param  BooleanAST  $node  The node to visit
     */
    public function visitBoolean(BooleanAST $node): void
    {
        $this->emit('PUSH', $node->value);
    }

    /**
     * Visit a Null node
     *
     * @param  NullAST  $node  The node to visit
     */
    public function visitNull(NullAST $node): void
    {
        $this->emit('PUSH', null);
    }

    /**
     * Visit an ArrayLiteral node
     *
     * @param  ArrayLiteralAST  $node  The node to visit
     */
    public function visitArrayLiteral(ArrayLiteralAST $node): void
    {
        // A literal of constants is built once, at compile time (an empty one is already one instruction)
        $constant = $node->entries === [] ? null : self::constantArray($node);
        if ($constant !== null) {
            $this->emit('PUSH', $constant);

            return;
        }

        $this->emit($node->map ? 'NEW_MAP' : 'NEW_ARRAY');
        foreach ($node->entries as [$key, $value]) {
            if ($key !== null) {
                $this->visit($key);
                $this->emit('KEY_CHECK');
            }
            $this->visit($value);
            $this->emit($key === null ? 'ARRAY_PUSH' : 'MAP_SET');
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
        $this->emit($node->existing ? 'INDEX_GET_EXISTING' : 'INDEX_GET');
    }

    /**
     * Visit a String node
     *
     * @param  StringAST  $node  The node to visit
     */
    public function visitString(StringAST $node): void
    {
        $this->emit('PUSH_STR', $node->value);
    }

    /**
     * Visit a Statement node
     *
     * @param  StatementAST  $node  The node to visit
     */
    public function visitStatement(StatementAST $node): void
    {
        // The result is discarded, so a postfix ++/-- can be the cheaper prefix form
        if ($node->expr instanceof IncrementAST && ! $node->expr->prefix) {
            $prefix = clone $node->expr;
            $prefix->prefix = true;
            $this->visit($prefix);
            $this->emit('POP');

            return;
        }

        // Evaluate the expression but don't output
        $this->visit($node->expr);
        $this->emit('POP');
    }

    /**
     * Visit an EchoStatement node
     *
     * @param  EchoStatementAST  $node  The node to visit
     */
    public function visitEchoStatement(EchoStatementAST $node): void
    {
        $this->visit($node->expr);
        $this->emit('PRINT');
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
        $this->emit('JZ', $else_label);

        // If block
        $this->visit($node->if_body);
        // Jump to end after executing if block
        $this->emit('JMP', $end_label);

        // Else or else-if block
        $this->emit('LABEL', $else_label);

        if ($node->else_if !== null) {
            // Handle else-if branch
            $this->visit($node->else_if);
        } elseif ($node->else_body !== null) {
            // Handle else branch
            $this->visit($node->else_body);
        }

        // End of if/else statement
        $this->emit('LABEL', $end_label);
    }

    /**
     * Visit a ForeachStatement node by emitting the equivalent while loop over keys()
     *
     * foreach ($array as $key => $value) { body } becomes, with hidden variables whose
     * names no program can write:
     *
     *   $#array = $array; $#keys = keys($#array); $#count = len($#keys); $#i = 0;
     *   while ($#i < $#count; step $#i = $#i + 1) { $key = $#keys[$#i]; $value = $#array[$#keys[$#i]]; body }
     *
     * The count is taken once: the loop iterates a copy, which the body can't change.
     *
     * The step runs on continue, as for for loops. FOREACH_CHECK fails on a non-array with
     * the interpreter's "foreach expects an array" message, leaving the value on the stack.
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
        $count = $hidden('count');
        $i = $hidden('i');
        $key = new IndexAST($keys, $i);

        $body = new CompoundAST;
        if ($node->key !== null) {
            $body->statements[] = $assign($node->key, $key);
        }
        $body->statements[] = $assign($node->value, new IndexAST($array, $key));
        array_push($body->statements, ...$node->body->statements);

        $this->visit($node->iterable);
        $this->emit('FOREACH_CHECK');
        $this->emitVariable('STORE', $array);

        $loop = new CompoundAST;
        $loop->statements = [
            $assign($keys, new FunctionCallAST('keys', [$array])),
            $assign($count, new FunctionCallAST('len', [$keys])),
            $assign($i, new NumAST(new Token(Token::INTEGER, 0))),
            new WhileStatementAST(
                new BinOpAST($i, new Token(Token::LESS_THAN, '<'), $count),
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

        $this->emit('LABEL', $start_label);
        $this->visit($node->condition);
        $this->emit('JZ', $end_label);

        $this->loop_labels[] = [$continue_label, $end_label, $this->try_depth];
        $this->visit($node->body);
        array_pop($this->loop_labels);

        if ($node->step !== null) {
            $this->emit('LABEL', $continue_label);
            $this->visit($node->step);
        }

        $this->emit('JMP', $start_label);
        $this->emit('LABEL', $end_label);
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

        $this->emit('TRY', $catch_label);
        $this->try_depth++;
        $this->visit($node->body);
        $this->try_depth--;
        $this->emit('END_TRY');
        $this->emit('JMP', $end_label);

        $this->emit('LABEL', $catch_label);
        $this->emitVariable('STORE', $node->variable);
        $this->visit($node->catch_body);
        $this->emit('LABEL', $end_label);
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
            $this->emit('END_TRY');
        }
        $this->emit('JMP', $label);
    }

    /**
     * Visit a Ternary node: the condition, JZ to the else branch, each branch leaving its value on the stack
     *
     * @param  TernaryAST  $node  The node to visit
     */
    public function visitTernary(TernaryAST $node): void
    {
        $else_label = 'TERNARY_ELSE_'.$this->label_counter;
        $end_label = 'TERNARY_END_'.$this->label_counter;
        $this->label_counter++;

        $this->visit($node->condition);
        $this->emit('JZ', $else_label);
        $this->visit($node->then);
        $this->emit('JMP', $end_label);
        $this->emit('LABEL', $else_label);
        $this->visit($node->else);
        $this->emit('LABEL', $end_label);
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

        if (isset(Builtins::ARITIES[$node->name])) {
            $this->emit('CALL_BUILTIN', $node->name, count($node->args));
        } else {
            $this->emit('CALL', "FN_{$node->name}", count($node->args));
        }
    }

    /**
     * Visit a FunctionRef node
     *
     * @param  FunctionRefAST  $node  The node to visit
     */
    public function visitFunctionRef(FunctionRefAST $node): void
    {
        $this->emit('PUSH_FN', $node->name);
    }

    /**
     * Visit a Lambda node: MAKE_CLOSURE, with the body compiled later by compile()
     *
     * The capture map is recorded now, while the enclosing frame's slots are known. A captured
     * variable the enclosing frame hasn't used yet still gets a slot, so a loop that assigns
     * it after the lambda captures it on the next iteration, as in the interpreter.
     *
     * @param  LambdaAST  $node  The node to visit
     */
    public function visitLambda(LambdaAST $node): void
    {
        $map = [];
        foreach ($node->captures as $i => $name) {
            $map[] = isset($this->captures[$name])
                ? [true, $this->captures[$name], $i]
                : [false, $this->var_addresses[$name] ??= count($this->var_addresses), $i];
        }
        $this->lambdas[] = [$node, $map];

        $this->emit('MAKE_CLOSURE', count($this->lambdas) - 1);
    }

    /**
     * Visit a CallValue node: the callee, then the arguments, then CALL_VALUE (the interpreter's order)
     *
     * @param  CallValueAST  $node  The node to visit
     */
    public function visitCallValue(CallValueAST $node): void
    {
        $this->visit($node->callee);
        foreach ($node->args as $arg) {
            $this->visit($arg);
        }

        $this->emit('CALL_VALUE', count($node->args));
    }

    /**
     * Visit a ReturnStatement node
     *
     * @param  ReturnStatementAST  $node  The node to visit
     */
    public function visitReturnStatement(ReturnStatementAST $node): void
    {
        if ($node->expr === null) {
            $this->emit('PUSH', null);
        } else {
            $this->visit($node->expr);
        }

        $this->emit('RET');
    }

    /**
     * Emit a function's prologue for its default parameter values
     *
     * ARGC pushes how many arguments the caller passed. For each parameter with a default,
     * if fewer than its position + 1 were passed, the default is evaluated and stored in the
     * parameter's slot, so later defaults can use it.
     *
     * @param  FunctionDeclarationAST|LambdaAST  $function  The function or lambda
     */
    private function defaultArguments(FunctionDeclarationAST|LambdaAST $function): void
    {
        foreach ($function->defaults as $i => $default) {
            if ($default === null) {
                continue;
            }
            $skip_label = 'PASSED_'.$this->label_counter++;

            $this->emit('ARGC');
            $this->emit('PUSH', $i);
            $this->emit('GT');
            $this->emit('NOT');
            $this->emit('JZ', $skip_label);
            $this->visit($default);
            $this->emit('STORE', $i);
            $this->emit('LABEL', $skip_label);
        }
    }

    /**
     * Compile the AST into a program for the VM
     */
    public function compile(): Program
    {
        $this->visit($this->tree);
        $local_names = ['' => array_keys($this->var_addresses)];
        $arities = [];

        if ($this->functions !== [] || $this->lambdas !== []) {
            $this->emit('HALT');
        }

        foreach ($this->functions as $function) {
            $arities[$function->name] = $function->arity;
            $local_names[$function->name] = $this->compileBody("FN_{$function->name}", $function, $function->params);
        }

        // A worklist: compiling a body can find more lambdas inside it. A lambda's frame is
        // keyed "->n", which no function name can be; its captured variables aren't in the
        // frame but in the closure, addressed by their index in LambdaAST::$captures
        for ($i = 0; $i < count($this->lambdas); $i++) {
            [$lambda] = $this->lambdas[$i];
            $local_names["->{$i}"] = $this->compileBody("LAMBDA_{$i}", $lambda, $lambda->params);
        }

        return new Program($this->instructions, $local_names, array_keys($this->global_addresses), $arities, $this->lambdas);
    }

    /**
     * Emit a function or lambda body in its own frame
     *
     * @param  string  $label  The entry label
     * @param  FunctionDeclarationAST|LambdaAST  $node  The function or lambda
     * @param  string[]  $slots  The names of the first local slots: the parameters
     * @return list<string> The variable name in each slot of the frame
     */
    private function compileBody(string $label, FunctionDeclarationAST|LambdaAST $node, array $slots): array
    {
        $this->var_addresses = array_flip($slots);
        $this->captures = $node instanceof LambdaAST ? $node->capture_names : [];
        [$this->file, $this->line] = [$node->file, $node->line];

        $this->emit('LABEL', $label);
        $this->defaultArguments($node);
        $this->visit($node->body);
        // Falling off the end returns null; a lambda's expression body leaves its value
        if (! $node instanceof LambdaAST || $node->isBlock()) {
            $this->emit('PUSH', null);
        }
        $this->emit('RET');

        return array_keys($this->var_addresses);
    }

    /**
     * Generate code from the AST, as text
     *
     * @return string The generated code, one instruction per line
     */
    public function generate(): string
    {
        return (string) $this->compile();
    }
}
