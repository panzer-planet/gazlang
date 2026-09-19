<?php

namespace GazLang\VM;

use Closure;
use Exception;
use GazLang\CodeGenerator\Program;
use GazLang\GazLangError;
use GazLang\Lexer\Token;
use GazLang\Runtime\Builtins;
use GazLang\Runtime\ClassValue;
use GazLang\Runtime\ExitSignal;
use GazLang\Runtime\FunctionValue;
use GazLang\Runtime\MapValue;
use GazLang\Runtime\ObjectValue;
use GazLang\Runtime\PropertyStep;
use GazLang\Runtime\Values;

/**
 * Runs a Program compiled by the CodeGenerator
 *
 * A stack machine: instructions push and pop values on one value stack; each function
 * call gets a frame of local slots; globals are one shared set of slots. Every operator
 * and builtin goes through Runtime\Values and Runtime\Builtins, the same code the
 * interpreter uses, so the two backends mean the same thing. See CodeGenerator for what
 * each instruction does.
 */
final class VM
{
    /**
     * The binary operator token each operator instruction applies, as [token type, symbol]
     */
    private const BINARY = [
        'ADD' => [Token::PLUS, '+'],
        'CONCAT' => [Token::CONCAT, '..'],
        'SUB' => [Token::MINUS, '-'],
        'MUL' => [Token::MULTIPLY, '*'],
        'DIV' => [Token::DIVIDE, '/'],
        'MOD' => [Token::MODULO, '%'],
        // No fast path: nothing measured uses them yet, so they go through Values like any other operator
        'BIT_AND' => [Token::BIT_AND, '&'],
        'BIT_OR' => [Token::BIT_OR, '|'],
        'BIT_XOR' => [Token::BIT_XOR, '^'],
        'SHL' => [Token::SHIFT_LEFT, '<<'],
        'SHR' => [Token::SHIFT_RIGHT, '>>'],
        'EQUALS' => [Token::EQUALS, '=='],
        'NOT_EQUALS' => [Token::NOT_EQUALS, '!='],
        'LT' => [Token::LESS_THAN, '<'],
        'LE' => [Token::LESS_EQUALS, '<='],
        'CMP' => [Token::SPACESHIP, '<=>'],
        'GT' => [Token::GREATER_THAN, '>'],
        'GE' => [Token::GREATER_EQUALS, '>='],
    ];

    /**
     * @var Program The program to run
     */
    private $program;

    /**
     * @var Builtins The builtin functions, which hold the program's command line arguments
     */
    private $builtins;

    /**
     * @var array The linked program (see link()), then the operator tokens, set by run()
     */
    private $linked;

    /**
     * Where the loop is running, as its pc, function and closure, set by the instructions that can run
     * program code from inside them (echo, .., ..=, a builtin), so a method they run is called from there
     */
    private int $here_pc = 0;

    private string $here_function = '';

    private ?FunctionValue $here_closure = null;

    /**
     * @var array The global variables by slot, shared by every execute()
     */
    private $globals = [];

    /**
     * Constructor
     *
     * @param  Program  $program  The program to run
     * @param  string[]  $args  Command line arguments for the program, returned by args()
     */
    public function __construct(Program $program, array $args = [])
    {
        $this->program = $program;
        $this->builtins = new Builtins($args);
    }

    /**
     * Run the program until HALT or its last instruction
     *
     * The hot loop: instructions are pre-split into parallel arrays by link(), and the
     * commonest operations on ints and bools skip the general Values functions. Those fast
     * paths only cover cases whose result is obvious (an int result that didn't overflow,
     * a comparison of two ints); anything else, errors included, goes through Values, and
     * the tests compare every program's output and errors with the interpreter's.
     *
     * @throws GazLangError If an error isn't caught by a try
     */
    public function run(): void
    {
        $tokens = [];
        foreach (self::BINARY as $opcode => [$type, $symbol]) {
            $tokens[$opcode] = new Token($type, $symbol);
        }
        $this->linked = [...$this->link(), $tokens, new Token(Token::INCREMENT, '++'), new Token(Token::DECREMENT, '--')];
        $this->globals = [];

        try {
            $this->execute(0, [], null, '', 0);
        } catch (GazLangError $error) {
            // Nothing caught it: a thrown value is only now turned into text, which can run its to_string()
            if (! $error->has_value) {
                throw $error;
            }
            $frames = [];
            $outer = [Values::$call_method, Values::$call_value];
            Values::$call_method = $this->methodCaller($frames, 0, null, false);
            Values::$call_value = $this->valueCaller($frames, 0, null, false);
            try {
                throw $error->uncaught();
            } finally {
                [Values::$call_method, Values::$call_value] = $outer;
            }
        }
    }

    /**
     * Run from an instruction until HALT, the end of the program, or the return of the frame it starts in
     *
     * The top level runs in one execute(). While it runs, Values::$call_method runs a method
     * to completion in a nested execute(), which is how echo and .. call to_string(): the
     * method's frame is the nested loop's first, and its RET returns the value. Errors the
     * nested loop doesn't catch leave it as exceptions, back into the instruction that called
     * the method, where the outer loop's handlers see them.
     *
     * @param  int  $pc  The instruction to start at
     * @param  array  $locals  The starting frame's locals (the arguments)
     * @param  ObjectValue|null  $receiver  The starting frame's object
     * @param  string  $function  The starting frame's key in the local names
     * @param  int  $depth  How many calls are running outside this loop, for the call depth limit
     * @param  Closure|null  $caller  The calls running outside this loop, as calls() gives them, or null for none
     * @param  FunctionValue|null  $closure  The closure the starting frame is a call of, if any
     * @return mixed The value the starting frame returns, or null at the end of the program
     *
     * @throws GazLangError If an error isn't caught by a try
     */
    private function execute(int $pc, array $locals, ?ObjectValue $receiver, string $function, int $depth, ?Closure $caller = null, ?FunctionValue $closure = null)
    {
        [$ops, $arg0, $arg1, $arg2, $locations, $functions, $lambdas, $classes, $initialisers, $tokens, $increment, $decrement] = $this->linked;
        $end = count($ops);
        $local_names = $this->program->local_names;
        $global_names = $this->program->global_names;
        $globals = &$this->globals;

        $stack = [];
        // How many arguments the running function was passed
        $argc = count($locals);
        // $closure is the closure whose call is running, or null; $receiver is the object # is, or null
        // Callers' state, innermost last: [locals, return pc, function, argc, closure, receiver]
        $frames = [];
        // Installed try handlers, innermost last: [frame count, stack size, catch pc]
        $handlers = [];

        $hooks = [Values::$call_method, Values::$call_value];
        Values::$call_method = $this->methodCaller($frames, $depth, $caller);
        Values::$call_value = $this->valueCaller($frames, $depth, $caller);

        try {
            while (true) {
                try {
                    while ($pc < $end) {
                        switch ($ops[$pc++]) {
                            case 'LOAD':
                                $slot = $arg0[$pc - 1];
                                if (isset($locals[$slot]) || array_key_exists($slot, $locals)) {
                                    $stack[] = $locals[$slot];
                                } else {
                                    throw new Exception("Undefined variable: {$local_names[$function][$slot]}");
                                }
                                break;
                            case 'STORE':
                                $locals[$arg0[$pc - 1]] = array_pop($stack);
                                break;
                                // ..= appends to the variable in place. The string is never loaded
                                // onto the stack, so PHP holds one reference to it and grows it
                                // rather than copying all of it on every append (Values::concatAssign)
                            case 'CONCAT_ASSIGN':
                                $slot = $arg0[$pc - 1];
                                if (! isset($locals[$slot]) && ! array_key_exists($slot, $locals)) {
                                    throw new Exception("Undefined variable: {$local_names[$function][$slot]}");
                                }
                                $this->here_pc = $pc;
                                $this->here_function = $function;
                                $this->here_closure = $closure;
                                $stack[] = Values::concatAssign($locals[$slot], array_pop($stack));
                                break;
                                // A closure's captured variables live in the closure, not the frame, so every
                                // call of it shares them; read them in place, never through a copy of the
                                // array, which would make writes copy what they hold
                            case 'LOAD_CAPTURED':
                                $slot = $arg0[$pc - 1];
                                if (isset($closure->captured[$slot]) || array_key_exists($slot, $closure->captured)) {
                                    $stack[] = $closure->captured[$slot];
                                } else {
                                    throw new Exception("Undefined variable: {$lambdas[$closure->index][2][$slot]}");
                                }
                                break;
                            case 'STORE_CAPTURED':
                                $closure->captured[$arg0[$pc - 1]] = array_pop($stack);
                                break;
                            case 'CONCAT_ASSIGN_CAPTURED':
                                $slot = $arg0[$pc - 1];
                                if (! isset($closure->captured[$slot]) && ! array_key_exists($slot, $closure->captured)) {
                                    throw new Exception("Undefined variable: {$lambdas[$closure->index][2][$slot]}");
                                }
                                $this->here_pc = $pc;
                                $this->here_function = $function;
                                $this->here_closure = $closure;
                                $stack[] = Values::concatAssign($closure->captured[$slot], array_pop($stack));
                                break;
                            case 'LOAD_QUIET_CAPTURED':
                                $stack[] = $closure->captured[$arg0[$pc - 1]] ?? null;
                                break;
                            case 'PUSH':
                                $stack[] = $arg0[$pc - 1];
                                break;
                            case 'POP':
                                array_pop($stack);
                                break;
                            case 'JMP':
                                $pc = $arg0[$pc - 1];
                                break;
                            case 'JZ':
                                $value = array_pop($stack);
                                if ($value === false || ($value !== true && ! Values::isTruthy($value))) {
                                    $pc = $arg0[$pc - 1];
                                }
                                break;
                            case 'ADD':
                                $right = array_pop($stack);
                                $left = array_pop($stack);
                                // An int result that overflows is a float in PHP, which Values reports as an error
                                // @phpstan-ignore booleanAnd.rightAlwaysTrue
                                if (is_int($left) && is_int($right) && is_int($result = $left + $right)) {
                                    $stack[] = $result;
                                } else {
                                    $stack[] = Values::binary($tokens['ADD'], $left, $right);
                                }
                                break;
                            case 'CONCAT':
                                $right = array_pop($stack);
                                $left = array_pop($stack);
                                if (is_string($left) && is_string($right)) {
                                    $stack[] = $left.$right;
                                } else {
                                    $this->here_pc = $pc;
                                    $this->here_function = $function;
                                    $this->here_closure = $closure;
                                    $stack[] = Values::binary($tokens['CONCAT'], $left, $right);
                                }
                                break;
                            case 'SUB':
                                $right = array_pop($stack);
                                $left = array_pop($stack);
                                // An int result that overflows is a float in PHP, which Values reports as an error
                                // @phpstan-ignore booleanAnd.rightAlwaysTrue
                                if (is_int($left) && is_int($right) && is_int($result = $left - $right)) {
                                    $stack[] = $result;
                                } else {
                                    $stack[] = Values::binary($tokens['SUB'], $left, $right);
                                }
                                break;
                            case 'MUL':
                                $right = array_pop($stack);
                                $left = array_pop($stack);
                                // An int result that overflows is a float in PHP, which Values reports as an error
                                // @phpstan-ignore booleanAnd.rightAlwaysTrue
                                if (is_int($left) && is_int($right) && is_int($result = $left * $right)) {
                                    $stack[] = $result;
                                } else {
                                    $stack[] = Values::binary($tokens['MUL'], $left, $right);
                                }
                                break;
                            case 'MOD':
                                $right = array_pop($stack);
                                $left = array_pop($stack);
                                // PHP_INT_MIN % -1 is 0 in PHP, as in Values
                                $stack[] = is_int($left) && is_int($right) && $right !== 0
                                    ? $left % $right
                                    : Values::binary($tokens['MOD'], $left, $right);
                                break;
                            case 'LT':
                            case 'LE':
                            case 'GT':
                            case 'GE':
                                $opcode = $ops[$pc - 1];
                                $right = array_pop($stack);
                                $left = array_pop($stack);
                                if (is_int($left) && is_int($right)) {
                                    $stack[] = match ($opcode) {
                                        'LT' => $left < $right,
                                        'LE' => $left <= $right,
                                        'GT' => $left > $right,
                                        default => $left >= $right,
                                    };
                                } else {
                                    $stack[] = Values::binary($tokens[$opcode], $left, $right);
                                }
                                break;
                            case 'EQUALS':
                            case 'NOT_EQUALS':
                                $right = array_pop($stack);
                                $left = array_pop($stack);
                                // Two ints or two strings are equal exactly when identical; Values::equals() decides the rest
                                $equal = (is_int($left) && is_int($right)) || (is_string($left) && is_string($right))
                                    ? $left === $right
                                    : Values::equals($left, $right);
                                $stack[] = $ops[$pc - 1] === 'EQUALS' ? $equal : ! $equal;
                                break;
                            case 'LOAD_QUIET':
                                $stack[] = $locals[$arg0[$pc - 1]] ?? null;
                                break;
                            case 'LOAD_QUIET_GLOBAL':
                                $stack[] = $globals[$arg0[$pc - 1]] ?? null;
                                break;
                            case 'INDEX_GET_QUIET':
                                $index = array_pop($stack);
                                $target = array_pop($stack);
                                // A map key that PHP stores as is (see INDEX_GET), or a list index: missing reads null
                                if ($target instanceof MapValue && (is_int($index) || (is_string($index) && $index !== '' && $index[0] > '9'))) {
                                    $stack[] = $target->items[$index] ?? null;
                                } elseif (is_array($target) && is_int($index)) {
                                    $stack[] = $target[$index] ?? null;
                                } else {
                                    $stack[] = $target === null ? null : Values::index($target, $index, true);
                                }
                                break;
                            case 'JNN':
                                if ($stack[array_key_last($stack)] !== null) {
                                    $pc = $arg0[$pc - 1];
                                } else {
                                    array_pop($stack);
                                }
                                break;
                            case 'LOAD_GLOBAL':
                                $slot = $arg0[$pc - 1];
                                if (isset($globals[$slot]) || array_key_exists($slot, $globals)) {
                                    $stack[] = $globals[$slot];
                                } else {
                                    throw new Exception("Undefined variable: {$global_names[$slot]}");
                                }
                                break;
                            case 'STORE_GLOBAL':
                                $globals[$arg0[$pc - 1]] = array_pop($stack);
                                break;
                            case 'CONCAT_ASSIGN_GLOBAL':
                                $slot = $arg0[$pc - 1];
                                if (! isset($globals[$slot]) && ! array_key_exists($slot, $globals)) {
                                    throw new Exception("Undefined variable: {$global_names[$slot]}");
                                }
                                $this->here_pc = $pc;
                                $this->here_function = $function;
                                $this->here_closure = $closure;
                                $stack[] = Values::concatAssign($globals[$slot], array_pop($stack));
                                break;
                            case 'PRINT':
                                $this->here_pc = $pc;
                                $this->here_function = $function;
                                $this->here_closure = $closure;
                                echo Values::toString(array_pop($stack)).PHP_EOL;
                                break;
                            case 'NOT':
                                $value = array_pop($stack);
                                $stack[] = $value === false || ($value !== true && ! Values::isTruthy($value));
                                break;
                            case 'NEG':
                                $stack[] = Values::negate(array_pop($stack));
                                break;
                            case 'NO_MATCH':
                                throw Values::noMatch(array_pop($stack));
                            case 'NO_CONDITION':
                                throw Values::noCondition();
                            case 'BIT_NOT':
                                $stack[] = Values::bitwiseNot(array_pop($stack));
                                break;
                            case 'INC':
                                $value = array_pop($stack);
                                $stack[] = is_int($value) && $value !== PHP_INT_MAX ? $value + 1 : Values::step($value, $increment);
                                break;
                            case 'DEC':
                                $value = array_pop($stack);
                                $stack[] = is_int($value) && $value !== PHP_INT_MIN ? $value - 1 : Values::step($value, $decrement);
                                break;
                            case 'NEW_ARRAY':
                                $stack[] = [];
                                break;
                            case 'ARRAY_PUSH':
                                $value = array_pop($stack);
                                $stack[array_key_last($stack)][] = $value;
                                break;
                            case 'ARRAY_EXTEND':
                                $value = Values::spread(array_pop($stack));
                                array_push($stack[array_key_last($stack)], ...$value);
                                break;
                            case 'NEW_MAP':
                                $stack[] = new MapValue;
                                break;
                            case 'MAP_SET':
                                $value = array_pop($stack);
                                $key = MapValue::key(Values::arrayKey(array_pop($stack)));
                                $stack[array_key_last($stack)]->items[$key] = $value;
                                break;
                            case 'INDEX_GET':
                                $index = array_pop($stack);
                                $target = array_pop($stack);
                                // A list element or a map key that PHP stores as is, when it isn't null
                                if (is_array($target) && is_int($index) && isset($target[$index])) {
                                    $stack[] = $target[$index];
                                } elseif ($target instanceof MapValue && (is_int($index) || (is_string($index) && $index !== '' && $index[0] > '9')) && isset($target->items[$index])) {
                                    $stack[] = $target->items[$index];
                                } else {
                                    $stack[] = Values::index($target, $index);
                                }
                                break;
                            case 'INDEX_GET_EXISTING':
                                $index = array_pop($stack);
                                $stack[] = Values::indexExisting(array_pop($stack), $index);
                                break;
                            case 'SET_PATH':
                                // Temporaries from earlier instructions may still hold the list or map being
                                // written; PHP would then copy all of it on every write
                                unset($first, $second, $left, $right, $target, $iterable, $args, $callee);
                                [$keys, $value] = $this->pathOperands($stack, $arg0[$pc - 1]);
                                $slot = $arg1[$pc - 1];
                                Values::store($locals, $slot, $local_names[$function][$slot], $keys, null, $value);
                                $stack[] = $value;
                                break;
                            case 'SET_PATH_CAPTURED':
                                unset($first, $second, $left, $right, $target, $iterable, $args, $callee);
                                [$keys, $value] = $this->pathOperands($stack, $arg0[$pc - 1]);
                                $slot = $arg1[$pc - 1];
                                Values::store($closure->captured, $slot, $lambdas[$closure->index][2][$slot], $keys, null, $value);
                                $stack[] = $value;
                                break;
                            case 'SET_PATH_GLOBAL':
                                unset($first, $second, $left, $right, $target, $iterable, $args, $callee);
                                [$keys, $value] = $this->pathOperands($stack, $arg0[$pc - 1]);
                                $slot = $arg1[$pc - 1];
                                Values::store($globals, $slot, $global_names[$slot], $keys, null, $value);
                                $stack[] = $value;
                                break;
                            case 'SET_PATH_THIS':
                                unset($first, $second, $left, $right, $target, $iterable, $args, $callee);
                                [$keys, $value] = $this->pathOperands($stack, $arg0[$pc - 1]);
                                // The object is a handle, so writing through a table holding it writes the object
                                $table = ['#' => $receiver];
                                Values::store($table, '#', '#', $keys, null, $value);
                                unset($table);
                                $stack[] = $value;
                                break;
                            case 'DELETE_PATH':
                                // As SET_PATH: temporaries may still hold the list or map being written
                                unset($first, $second, $left, $right, $target, $iterable, $args, $callee);
                                $keys = $this->pathOperands($stack, $arg0[$pc - 1], false)[0];
                                $slot = $arg1[$pc - 1];
                                Values::remove($locals, $slot, $local_names[$function][$slot], $keys);
                                break;
                            case 'DELETE_PATH_GLOBAL':
                                unset($first, $second, $left, $right, $target, $iterable, $args, $callee);
                                $keys = $this->pathOperands($stack, $arg0[$pc - 1], false)[0];
                                $slot = $arg1[$pc - 1];
                                Values::remove($globals, $slot, $global_names[$slot], $keys);
                                break;
                            case 'DELETE_PATH_CAPTURED':
                                unset($first, $second, $left, $right, $target, $iterable, $args, $callee);
                                $keys = $this->pathOperands($stack, $arg0[$pc - 1], false)[0];
                                $slot = $arg1[$pc - 1];
                                Values::remove($closure->captured, $slot, $lambdas[$closure->index][2][$slot], $keys);
                                break;
                            case 'DELETE_PATH_THIS':
                                unset($first, $second, $left, $right, $target, $iterable, $args, $callee);
                                $keys = $this->pathOperands($stack, $arg0[$pc - 1], false)[0];
                                // The object is a handle, so removing through a table holding it writes the object
                                $table = ['#' => $receiver];
                                Values::remove($table, '#', '#', $keys);
                                unset($table);
                                break;
                            case 'GET_PROPERTY_QUIET':
                                $target = array_pop($stack);
                                $stack[] = $target === null ? null : Values::property($target, $arg0[$pc - 1], true);
                                break;
                            case 'GET_PROPERTY_EXISTING':
                                $target = array_pop($stack);
                                $stack[] = Values::propertyExisting($target, $arg0[$pc - 1]);
                                break;
                            case 'DESTRUCTURE':
                                $value = $stack[array_key_last($stack)];
                                if (! is_array($value) || count($value) !== $arg0[$pc - 1]) {
                                    Values::destructure($value, $arg0[$pc - 1]);
                                }
                                break;
                            case 'KEY_CHECK':
                                $key = $stack[array_key_last($stack)];
                                if (! is_int($key) && ! is_string($key)) {
                                    Values::arrayKey($key);
                                }
                                break;
                            case 'FOREACH_CHECK':
                                $iterable = $stack[array_key_last($stack)];
                                if (! is_array($iterable) && ! $iterable instanceof MapValue) {
                                    throw new Exception('foreach expects a list or map, got '.Values::typeOf($iterable));
                                }
                                break;
                            case 'CALL':
                                if ($depth + count($frames) === Values::MAX_CALL_DEPTH) {
                                    throw new Exception('Maximum call depth of '.Values::MAX_CALL_DEPTH." exceeded calling {$arg2[$pc - 1]}");
                                }
                                $frames[] = [$locals, $pc, $function, $argc, $closure, $receiver];
                                $argc = $arg1[$pc - 1];
                                $locals = $this->popMany($stack, $argc);
                                $closure = null;
                                $receiver = null;
                                $function = $arg2[$pc - 1];
                                $pc = $arg0[$pc - 1];
                                break;
                            case 'PUSH_FN':
                                $stack[] = FunctionValue::named($arg0[$pc - 1]);
                                break;
                            case 'MAKE_CLOSURE':
                                $index = $arg0[$pc - 1];
                                [, , , $self, $map] = $lambdas[$index];
                                // Copies of the enclosing variables that exist, from the frame or from the running
                                // closure's own, as in the interpreter
                                $captured = [];
                                foreach ($map as [$from_closure, $outer, $inner]) {
                                    if ($from_closure) {
                                        if (isset($closure->captured[$outer]) || array_key_exists($outer, $closure->captured)) {
                                            $captured[$inner] = $closure->captured[$outer];
                                        }
                                    } elseif (isset($locals[$outer]) || array_key_exists($outer, $locals)) {
                                        $captured[$inner] = $locals[$outer];
                                    }
                                }
                                // The closure is made where the lambda is written, which is this instruction's location
                                $made = FunctionValue::closure(null, $captured, $index, $receiver, ...$locations[$pc - 1]);
                                // $f = <lambda>: the closure's $f is the closure
                                if ($self !== null) {
                                    $made->captured[$self] = $made;
                                }
                                $stack[] = $made;
                                unset($made, $captured);
                                break;
                            case 'LOAD_THIS':
                                $stack[] = $receiver;
                                break;
                            case 'LOAD_FIELD':
                                $name = $arg0[$pc - 1];
                                if (isset($receiver->fields[$name]) || array_key_exists($name, $receiver->fields)) {
                                    $stack[] = $receiver->fields[$name];
                                } else {
                                    // Not set: Values gives the error
                                    $stack[] = Values::property($receiver, $name);
                                }
                                break;
                            case 'GET_PROPERTY':
                                $target = array_pop($stack);
                                $stack[] = Values::property($target, $arg0[$pc - 1]);
                                break;
                            case 'SET_FIELD':
                                $receiver->fields[$arg0[$pc - 1]] = $stack[array_key_last($stack)];
                                break;
                            case 'GET_METHOD':
                                $target = array_pop($stack);
                                $name = $arg0[$pc - 1];
                                if ($target instanceof ObjectValue && isset($target->class->entries[$name])) {
                                    $stack[] = $target;
                                    $stack[] = $target->class->entries[$name];
                                } else {
                                    $stack[] = Values::property($target, $name);
                                    $stack[] = null;
                                }
                                break;
                            case 'CALL_METHOD':
                                // Most methods take no argument or one: pop those directly
                                $count = $arg0[$pc - 1];
                                $args = match ($count) {
                                    0 => [],
                                    1 => [array_pop($stack)],
                                    default => $this->popMany($stack, $count),
                                };
                                $method = array_pop($stack);
                                $callee = array_pop($stack);
                                if ($method === null) {
                                    // A field holding a function, or whatever else the member was
                                    goto call_value;
                                }
                                // [entry, arity, "Class.name"], from GET_METHOD
                                if ($method[1] !== $count && ! Builtins::fitsArity($method[1], $count)) {
                                    throw new Exception(Builtins::arityError("Method {$method[2]}", $method[1], $count));
                                }
                                if ($depth + count($frames) === Values::MAX_CALL_DEPTH) {
                                    throw new Exception('Maximum call depth of '.Values::MAX_CALL_DEPTH." exceeded calling {$method[2]}");
                                }
                                $frames[] = [$locals, $pc, $function, $argc, $closure, $receiver];
                                [$locals, $argc, $function, $closure, $receiver, $pc] = [$args, $count, $method[2], null, $callee, $method[0]];
                                break;
                            case 'NEW':
                                $args = $this->popMany($stack, $arg1[$pc - 1]);
                                $callee = $classes[$arg0[$pc - 1]];
                                goto construct;
                            case 'CALL_CONSTRUCTOR':
                                // The constructor runs with the arguments the object's initialiser was given, and
                                // nothing else it holds; a failure here is where the object is being made, as in
                                // the interpreter, not in the initialiser
                                if ($depth + count($frames) === Values::MAX_CALL_DEPTH) {
                                    throw $this->locate(
                                        new Exception('Maximum call depth of '.Values::MAX_CALL_DEPTH." exceeded calling {$arg0[$pc - 1]}._"),
                                        $locations[$frames[array_key_last($frames)][1] - 1],
                                        $frames,
                                        $function,
                                        $closure,
                                        $locations,
                                        $caller
                                    );
                                }
                                $frames[] = [$locals, $pc, $function, $argc, $closure, $receiver];
                                $locals = array_slice($locals, 0, $argc);
                                $function = "{$arg0[$pc - 1]}._";
                                $pc = $functions[$function][0];
                                break;
                            case 'CALL_PARENT':
                                $args = $this->popMany($stack, $arg2[$pc - 1]);
                                $name = "{$arg0[$pc - 1]}.{$arg1[$pc - 1]}";
                                if ($depth + count($frames) === Values::MAX_CALL_DEPTH) {
                                    throw new Exception('Maximum call depth of '.Values::MAX_CALL_DEPTH." exceeded calling {$name}");
                                }
                                $frames[] = [$locals, $pc, $function, $argc, $closure, $receiver];
                                [$locals, $argc, $function, $closure, $pc] = [$args, count($args), $name, null, $functions[$name][0]];
                                break;
                            case 'BIND_PARENT':
                                $stack[] = FunctionValue::bound($receiver, $classes[$arg0[$pc - 1]], $arg1[$pc - 1]);
                                break;
                            case 'PUSH_CLASS':
                                $stack[] = $classes[$arg0[$pc - 1]];
                                break;
                            case 'CALL_VALUE':
                                $args = $this->popMany($stack, $arg0[$pc - 1]);
                                $callee = array_pop($stack);
                                call_value:
                                if ($callee instanceof ClassValue) {
                                    if ($callee->abstract) {
                                        throw new Exception("Cannot construct abstract class {$callee->name}");
                                    }
                                    if (! Builtins::fitsArity($callee->arity, count($args))) {
                                        throw new Exception(Builtins::arityError("Class {$callee->name}", $callee->arity, count($args)));
                                    }
                                    construct:
                                    // One frame sets the field defaults and runs the constructor, with the object as receiver
                                    if ($depth + count($frames) === Values::MAX_CALL_DEPTH) {
                                        throw new Exception('Maximum call depth of '.Values::MAX_CALL_DEPTH." exceeded calling {$callee->name}");
                                    }
                                    $frames[] = [$locals, $pc, $function, $argc, $closure, $receiver];
                                    [$locals, $argc, $function, $closure, $receiver, $pc] = [$args, count($args), "new {$callee->name}", null, new ObjectValue($callee), $initialisers[$callee->name]];
                                    break;
                                }
                                if (! $callee instanceof FunctionValue) {
                                    throw new Exception('Cannot call '.Values::typeOf($callee));
                                }
                                $name = $callee->name;
                                $builtin = $name !== null && $callee->class === null && isset(Builtins::ARITIES[$name]);
                                $arity = match (true) {
                                    $name === null => $lambdas[$callee->index][1],
                                    $callee->class !== null => $functions["{$callee->class->name}.{$name}"][1],
                                    $builtin => Builtins::ARITIES[$name],
                                    default => $functions[$name][1],
                                };
                                if (! Builtins::fitsArity($arity, count($args))) {
                                    throw new Exception(Builtins::arityError($callee->title(), $arity, count($args)));
                                }
                                if ($builtin) {
                                    $this->here_pc = $pc;
                                    $this->here_function = $function;
                                    $this->here_closure = $closure;
                                    $stack[] = $this->builtins->call($name, $args);
                                    break;
                                }
                                // The same frame push as CALL, with the arguments already popped; a closure's
                                // body reads its captured variables from the closure
                                if ($depth + count($frames) === Values::MAX_CALL_DEPTH) {
                                    throw new Exception('Maximum call depth of '.Values::MAX_CALL_DEPTH.' exceeded calling '.$callee->describe());
                                }
                                $frames[] = [$locals, $pc, $function, $argc, $closure, $receiver];
                                $argc = count($args);
                                $locals = $args;
                                $closure = $name === null ? $callee : null;
                                $receiver = $callee->receiver;
                                if ($name === null) {
                                    // Keyed so no function name can collide: names can't contain ->
                                    $function = "->{$callee->index}";
                                    $pc = $lambdas[$callee->index][0];
                                } elseif ($callee->class !== null) {
                                    $function = "{$callee->class->name}.{$name}";
                                    $pc = $functions[$function][0];
                                } else {
                                    $function = $name;
                                    $pc = $functions[$name][0];
                                }
                                break;
                            case 'ARGC':
                                $stack[] = $argc;
                                break;
                            case 'RET':
                                // Handlers installed by this call are gone with its frame
                                while ($handlers !== [] && $handlers[array_key_last($handlers)][0] === count($frames)) {
                                    array_pop($handlers);
                                }
                                // The frame this loop started in returns its value to whoever called execute()
                                if ($frames === []) {
                                    return array_pop($stack);
                                }
                                [$locals, $pc, $function, $argc, $closure, $receiver] = array_pop($frames);
                                break;
                            case 'CALL_BUILTIN':
                                $name = $arg0[$pc - 1];
                                // Most builtins take one or two arguments, so pop those directly
                                switch ($arg1[$pc - 1]) {
                                    case 1:
                                        $first = array_pop($stack);
                                        // Fast paths for the hottest builtins when the result is obvious;
                                        // anything else, errors included, goes through Builtins::call()
                                        if ($name === 'len' && is_string($first)) {
                                            $stack[] = strlen($first);
                                        } elseif ($name === 'ord' && is_string($first) && strlen($first) === 1) {
                                            $stack[] = ord($first);
                                        } elseif ($name === 'len' && is_array($first)) {
                                            $stack[] = count($first);
                                        } elseif ($name === 'chr' && is_int($first) && $first >= 0 && $first <= 255) {
                                            $stack[] = chr($first);
                                        } else {
                                            $this->here_pc = $pc;
                                            $this->here_function = $function;
                                            $this->here_closure = $closure;
                                            $stack[] = $this->builtins->call($name, [$first]);
                                        }
                                        break;
                                    case 2:
                                        $second = array_pop($stack);
                                        $first = array_pop($stack);
                                        $this->here_pc = $pc;
                                        $this->here_function = $function;
                                        $this->here_closure = $closure;
                                        $stack[] = $this->builtins->call($name, [$first, $second]);
                                        break;
                                    default:
                                        $this->here_pc = $pc;
                                        $this->here_function = $function;
                                        $this->here_closure = $closure;
                                        $stack[] = $this->builtins->call($name, $this->popMany($stack, $arg1[$pc - 1]));
                                }
                                break;
                            case 'TRY':
                                $handlers[] = [count($frames), count($stack), $arg0[$pc - 1]];
                                break;
                            case 'END_TRY':
                                array_pop($handlers);
                                break;
                            case 'CATCH_VALUE':
                                $stack[] = array_pop($stack)->caught($classes['Error']);
                                break;
                            case 'CATCH_MATCH':
                                // The error stays for the next clause, or becomes what this one catches
                                $value = $stack[array_key_last($stack)]->caught($classes['Error']);
                                if ($value instanceof ObjectValue && $value->class->isA($classes[$arg0[$pc - 1]])) {
                                    $stack[array_key_last($stack)] = $value;
                                } else {
                                    $pc = $arg1[$pc - 1];
                                }
                                break;
                            case 'RETHROW':
                                throw array_pop($stack);
                            case 'HALT':
                                return null;
                            default:
                                $opcode = $ops[$pc - 1];
                                if (! isset($tokens[$opcode])) {
                                    throw new Exception("Unknown instruction: {$opcode}");
                                }
                                $right = array_pop($stack);
                                $stack[] = Values::binary($tokens[$opcode], array_pop($stack), $right);
                        }
                    }

                    return null;
                } catch (ExitSignal $e) {
                    // exit() is not an error: no handler sees it
                    throw $e;
                } catch (Exception $e) {
                    $error = $this->locate($e, $locations[$pc - 1], $frames, $function, $closure, $locations, $caller);
                    if (! $error instanceof GazLangError || $handlers === []) {
                        throw $error;
                    }

                    // Unwind to the innermost try: drop the calls made inside it and whatever
                    // the failed expression left on the stack, then run its catch block
                    [$frame_count, $stack_size, $catch_pc] = array_pop($handlers);
                    while (count($frames) > $frame_count) {
                        [$locals, , $function, $argc, $closure, $receiver] = array_pop($frames);
                    }
                    array_splice($stack, $stack_size);
                    // The error itself: CATCH_VALUE turns it into what catch sees, and a finally handler rethrows it
                    $stack[] = $error;
                    $pc = $catch_pc;
                }
            }
        } finally {
            [Values::$call_method, Values::$call_value] = $hooks;
        }
    }

    /**
     * How Values::$call_method runs a method while a loop runs: to completion, in a nested execute()
     *
     * @param  array  $frames  The running loop's frames, by reference, so the call depth counts them as they are then
     * @param  int  $depth  How many calls are running outside that loop
     * @param  Closure|null  $caller  The calls running outside that loop, or null for none
     * @param  bool  $running  Whether that loop is running (false once the program has ended, printing an
     *                         uncaught error), so the method is called from where it is
     */
    private function methodCaller(array &$frames, int $depth, ?Closure $caller, bool $running = true): Closure
    {
        $functions = $this->linked[5];

        return function (ObjectValue $object, ClassValue $definer, string $name) use (&$frames, $depth, $caller, $running, $functions) {
            $name = "{$definer->name}.{$name}";
            if ($depth + count($frames) === Values::MAX_CALL_DEPTH) {
                throw new Exception('Maximum call depth of '.Values::MAX_CALL_DEPTH." exceeded calling {$name}");
            }

            return $this->nested($functions[$name][0], [], $object, $name, null, $frames, $depth, $caller, $running);
        };
    }

    /**
     * How Values::$call_value calls a value while a loop runs, as map() calls its callback: checked as
     * CALL_VALUE checks it, then to completion in a nested execute(), or directly for a builtin
     *
     * @param  array  $frames  The running loop's frames, by reference, so the call depth counts them as they are then
     * @param  int  $depth  How many calls are running outside that loop
     * @param  Closure|null  $caller  The calls running outside that loop, or null for none
     * @param  bool  $running  Whether that loop is running, as for methodCaller()
     */
    private function valueCaller(array &$frames, int $depth, ?Closure $caller, bool $running = true): Closure
    {
        return function ($callee, array $args) use (&$frames, $depth, $caller, $running) {
            $target = $this->callTarget($callee, count($args));
            if ($target === null) {
                return $this->builtins->call($callee->name, $args);
            }
            [$entry, $key, $closure, $receiver, $display] = $target;
            if ($depth + count($frames) === Values::MAX_CALL_DEPTH) {
                throw new Exception('Maximum call depth of '.Values::MAX_CALL_DEPTH." exceeded calling {$display}");
            }

            return $this->nested($entry, $args, $receiver, $key, $closure, $frames, $depth, $caller, $running);
        };
    }

    /**
     * Run a call to completion in a nested execute(), called from where the running instruction said it is
     *
     * The calls there are worked out only if an error needs them. Where it is is put back afterwards,
     * since the nested loop's own instructions change it, and the instruction may call again (join()
     * printing a second object, map() calling back for the next element).
     *
     * @param  int  $entry  Where the call starts
     * @param  array  $args  The arguments
     * @param  ObjectValue|null  $receiver  The object # is, or null
     * @param  string  $key  The called function's key in the local names
     * @param  FunctionValue|null  $closure  The closure called, or null
     * @param  array  $frames  The running loop's frames, as they are now
     * @param  int  $depth  How many calls are running outside that loop
     * @param  Closure|null  $caller  The calls running outside that loop, or null for none
     * @param  bool  $running  Whether that loop is running, so the call is made from where it is
     * @return mixed What the call returns
     */
    private function nested(int $entry, array $args, ?ObjectValue $receiver, string $key, ?FunctionValue $closure, array $frames, int $depth, ?Closure $caller, bool $running)
    {
        [$pc, $function, $running_closure] = [$this->here_pc, $this->here_function, $this->here_closure];
        $here = null;
        if ($running) {
            $locations = $this->linked[4];
            $here = fn () => $this->calls($locations[$pc - 1], $frames, $function, $running_closure, $locations, $caller);
        }
        try {
            return $this->execute($entry, $args, $receiver, $key, $depth + count($frames) + 1, $here, $closure);
        } finally {
            [$this->here_pc, $this->here_function, $this->here_closure] = [$pc, $function, $running_closure];
        }
    }

    /**
     * Check a value can be called with that many arguments, as CALL_VALUE does, and say where the call goes
     *
     * @param  mixed  $callee  The value called
     * @param  int  $argc  How many arguments it is given
     * @return array{0: int, 1: string, 2: FunctionValue|null, 3: ObjectValue|null, 4: string}|null The entry, the
     *                                                                                              function's key, the closure,
     *                                                                                              the object # is and the name
     *                                                                                              for the call depth error; null
     *                                                                                              for a builtin, which runs in PHP
     *
     * @throws Exception If the value can't be called, or not with that many arguments
     */
    private function callTarget($callee, int $argc): ?array
    {
        [, , , , , $functions, $lambdas, , $initialisers] = $this->linked;
        if ($callee instanceof ClassValue) {
            if ($callee->abstract) {
                throw new Exception("Cannot construct abstract class {$callee->name}");
            }
            if (! Builtins::fitsArity($callee->arity, $argc)) {
                throw new Exception(Builtins::arityError("Class {$callee->name}", $callee->arity, $argc));
            }

            // One frame sets the field defaults and runs the constructor, with the object as receiver
            return [$initialisers[$callee->name], "new {$callee->name}", null, new ObjectValue($callee), $callee->name];
        }
        if (! $callee instanceof FunctionValue) {
            throw new Exception('Cannot call '.Values::typeOf($callee));
        }
        $name = $callee->name;
        $builtin = $name !== null && $callee->class === null && isset(Builtins::ARITIES[$name]);
        $arity = match (true) {
            $name === null => $lambdas[$callee->index][1],
            $callee->class !== null => $functions["{$callee->class->name}.{$name}"][1],
            $builtin => Builtins::ARITIES[$name],
            default => $functions[$name][1],
        };
        if (! Builtins::fitsArity($arity, $argc)) {
            throw new Exception(Builtins::arityError($callee->title(), $arity, $argc));
        }
        if ($builtin) {
            return null;
        }
        if ($name === null) {
            // Keyed so no function name can collide: names can't contain ->
            return [$lambdas[$callee->index][0], "->{$callee->index}", $callee, $callee->receiver, $callee->describe()];
        }
        $key = $callee->class !== null ? "{$callee->class->name}.{$name}" : $name;

        return [$functions[$key][0], $key, null, $callee->receiver, $callee->describe()];
    }

    /**
     * Prepare the program to run: concatenate the blocks, simplify, resolve labels, and split into parallel arrays
     *
     * - The top level block comes first and ends in HALT, so it never falls into a function.
     * - STORE x; LOAD x; POP (an assignment used as a statement) becomes STORE x.
     * - LABEL instructions are dropped; JMP, JZ, JNN, TRY and CATCH_MATCH get the position to
     *   jump to, resolved in their own block, and CALL the called function's entry position,
     *   its argument count and its name (for the call depth error).
     * - Each instruction's opcode and first three arguments go into their own arrays, so
     *   the loop reads what it needs without unpacking an instruction each time, and its
     *   [file, line] into $locations, only read when there is an error.
     * - Each function's and method's entry position and arity go into $functions, for CALL_VALUE,
     *   methods keyed "Class.name"; each lambda's entry position, arity, captured names, self
     *   and capture map into $lambdas.
     * - The classes are built as values into $classes, with the entry of the code that makes
     *   each one's objects in $initialisers.
     *
     * @return array{0: list<string>, 1: list<mixed>, 2: list<mixed>, 3: list<mixed>, 4: list<array{0: string|null, 1: int|null}>, 5: array<string, array{0: int, 1: int|array{0: int, 1: int}}>, 6: list<array{0: int, 1: int|array{0: int, 1: int}, 2: list<string>, 3: int|null, 4: list<array{0: bool, 1: int, 2: int}>}>, 7: array<string, ClassValue>, 8: array<string, int>}
     */
    private function link(): array
    {
        $ops = $arg0 = $arg1 = $arg2 = $locations = [];
        $entries = [];
        $blocks = [];
        foreach ($this->program->blocks as $block) {
            $key = Program::key($block);
            $entries[$key] = count($ops);
            $labels = [];
            $start = count($ops);
            foreach (self::simplify($block['code']) as [$opcode, $args, $file, $line]) {
                if ($opcode === 'LABEL') {
                    $labels[$args[0]] = count($ops);

                    continue;
                }
                $ops[] = $opcode;
                $arg0[] = $args[0] ?? null;
                $arg1[] = $args[1] ?? null;
                $arg2[] = $args[2] ?? null;
                $locations[] = [$file, $line];
            }
            if ($key === '') {
                $ops[] = 'HALT';
                $arg0[] = $arg1[] = $arg2[] = null;
                $locations[] = [null, null];
            }
            $blocks[] = [$start, count($ops), $labels];
        }

        // The reader only checks the code it can reach, so an unreachable jump or call may name
        // what isn't there; it never runs, so it resolves to nothing rather than a warning
        foreach ($blocks as [$start, $end, $labels]) {
            for ($pc = $start; $pc < $end; $pc++) {
                if (in_array($ops[$pc], ['JMP', 'JZ', 'JNN', 'TRY'], true)) {
                    $arg0[$pc] = $labels[$arg0[$pc]] ?? null;
                } elseif ($ops[$pc] === 'CATCH_MATCH') {
                    $arg1[$pc] = $labels[$arg1[$pc]] ?? null;
                } elseif ($ops[$pc] === 'CALL') {
                    $arg2[$pc] = $arg0[$pc];
                    $arg0[$pc] = $entries[$arg0[$pc]] ?? null;
                } elseif (str_starts_with($ops[$pc], 'SET_PATH') || str_starts_with($ops[$pc], 'DELETE_PATH')) {
                    $arg0[$pc] = self::parsePath($arg0[$pc]);
                }
            }
        }

        // Functions and methods share one table: a method is keyed "Class.name", which no function name can be
        $functions = [];
        foreach ($this->program->functions as $name => $arity) {
            $functions[$name] = [$entries[$name], $arity];
        }
        $lambdas = [];
        foreach ($this->program->lambdas as $index => $lambda) {
            $lambdas[$index] = [$entries["->{$index}"], $lambda['arity'], $lambda['captures'], $lambda['self'], $lambda['map']];
        }
        $classes = ClassValue::build($this->program->classes, $this->program->functions);
        $initialisers = [];
        foreach ($this->program->classes as $name => $class) {
            $initialisers[$name] = $entries["new {$name}"];
        }
        foreach ($classes as $class) {
            foreach ($class->methods as $method => $definer) {
                if ($method !== '_') {
                    $key = "{$definer->name}.{$method}";
                    $class->entries[$method] = [$functions[$key][0], $functions[$key][1], $key];
                }
            }
        }

        return [$ops, $arg0, $arg1, $arg2, $locations, $functions, $lambdas, $classes, $initialisers];
    }

    /**
     * Collapse STORE x; LOAD x; POP, an assignment used as a statement, into STORE x
     *
     * @param  list<array{0: string, 1: array, 2: string|null, 3: int|null}>  $code  A block's instructions
     * @return list<array{0: string, 1: array, 2: string|null, 3: int|null}> The instructions to run
     */
    private static function simplify(array $code): array
    {
        $simplified = [];
        foreach ($code as $instruction) {
            $simplified[] = $instruction;
            $n = count($simplified);
            if ($n >= 3 && $instruction[0] === 'POP'
                && in_array($simplified[$n - 3][0], ['STORE', 'STORE_GLOBAL'], true)
                && $simplified[$n - 2][0] === ($simplified[$n - 3][0] === 'STORE' ? 'LOAD' : 'LOAD_GLOBAL')
                && $simplified[$n - 2][1] === $simplified[$n - 3][1]) {
                array_splice($simplified, -2);
            }
        }

        return $simplified;
    }

    /**
     * Read a SET_PATH path ([k] a key from the stack, .name a field, [] an append) into what pathOperands() needs
     *
     * @param  string  $path  The path, like [k].total[]
     * @return array{0: int, 1: list<PropertyStep|string|null>|null} How many keys to pop, and the steps with "k" where
     *                                                               each key goes, or null when the path is only keys
     */
    private static function parsePath(string $path): array
    {
        preg_match_all('/\[k\]|\[\]|\.\w+/', $path, $matches);
        $steps = array_map(fn ($step) => match ($step) {
            '[k]' => 'k',
            '[]' => null,
            default => new PropertyStep(substr($step, 1)),
        }, $matches[0]);
        $count = count(array_keys($steps, 'k', true));

        return [$count, $count === count($steps) ? null : $steps];
    }

    /**
     * Pop the operands of SET_PATH or DELETE_PATH: the value, if there is one, and the keys pushed before it
     *
     * @param  array  $stack  The value stack, by reference
     * @param  array{0: int, 1: list<PropertyStep|string|null>|null}  $path  The parsed path
     * @param  bool  $with_value  Whether a value was pushed after the keys, as an assignment does
     * @return array{0: array, 1: mixed} The steps, outermost first, and the value
     */
    private function pathOperands(array &$stack, array $path, bool $with_value = true): array
    {
        [$count, $steps] = $path;
        $value = $with_value ? array_pop($stack) : null;
        // Already checked by KEY_CHECK when they were pushed
        $keys = $this->popMany($stack, $count);
        if ($steps === null) {
            return [$keys, $value];
        }

        $i = 0;
        foreach ($steps as $n => $step) {
            if ($step === 'k') {
                $steps[$n] = $keys[$i++];
            }
        }

        return [$steps, $value];
    }

    /**
     * Pop the top $count values, returning them in the order they were pushed
     *
     * Not array_splice(), which rebuilds the whole stack and so made each call cost time
     * in proportion to the stack's size.
     *
     * @param  array  $stack  The value stack, by reference
     * @param  int  $count  How many values to pop
     * @return list<mixed> The values, bottom first
     */
    private function popMany(array &$stack, int $count): array
    {
        if ($count === 0) {
            return [];
        }
        $values = array_slice($stack, -$count);
        for ($i = 0; $i < $count; $i++) {
            array_pop($stack);
        }

        return $values;
    }

    /**
     * Give an error the location of the instruction that raised it, and the calls running then, unless it has one
     *
     * @param  Exception  $error  The error
     * @param  array{0: string|null, 1: int|null}  $location  The [file, line] of the instruction that raised it
     * @param  list<array>  $frames  The callers of the running frame, outermost first
     * @param  string  $function  The running frame's key in the local names
     * @param  FunctionValue|null  $closure  The closure the running frame is a call of, if any
     * @param  list<array{0: string|null, 1: int|null}>  $locations  Every instruction's location
     * @param  Closure|null  $caller  The calls running outside this loop, or null for none
     */
    private function locate(Exception $error, array $location, array $frames, string $function, ?FunctionValue $closure, array $locations, ?Closure $caller): Exception
    {
        [$file, $line] = $location;
        if ($line === null || ($error instanceof GazLangError && $error->line_number !== null)) {
            return $error;
        }

        $trace = GazLangError::trace($this->calls($location, $frames, $function, $closure, $locations, $caller));
        if ($error instanceof GazLangError) {
            $error->trace ??= $trace;

            return $error->located($file, $line);
        }
        $located = new GazLangError($error->getMessage(), $file, $line);
        $located->trace = $trace;

        return $located;
    }

    /**
     * The calls running, innermost first, each as [what it is, file, line], for an error raised at a location
     *
     * Each call is shown where it was running: the innermost where the error happened, the ones
     * around it at the call they made, which is the instruction before the one they return to.
     * A method run from inside an instruction (to_string() by printing) runs in a loop of its
     * own, whose caller is where the loop around it is running, as in the interpreter.
     *
     * @param  array{0: string|null, 1: int|null}  $location  Where the running frame is
     * @param  list<array>  $frames  The callers of the running frame, outermost first
     * @param  string  $function  The running frame's key in the local names
     * @param  FunctionValue|null  $closure  The closure the running frame is a call of, if any
     * @param  list<array{0: string|null, 1: int|null}>  $locations  Every instruction's location
     * @param  Closure|null  $caller  The calls running outside this loop, or null for none
     * @return list<array{0: string, 1: string|null, 2: int|null}>
     */
    private function calls(array $location, array $frames, string $function, ?FunctionValue $closure, array $locations, ?Closure $caller): array
    {
        $calls = [];
        $at = $location;
        $i = count($frames);
        while (true) {
            // A lambda is "->": the trace says where it was running, not where it was written
            $calls[] = [$closure !== null ? '->' : ($function === '' ? 'top level' : $function), ...$at];
            if ($i === 0) {
                return $caller === null ? $calls : [...$calls, ...$caller()];
            }
            // The caller was at the call it made, the instruction before the one it returns to
            [, $return_pc, $function, , $closure] = $frames[--$i];
            $at = $locations[$return_pc - 1];
        }
    }
}
