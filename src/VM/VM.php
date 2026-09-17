<?php

namespace GazLang\VM;

use Exception;
use GazLang\AST\LambdaAST;
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
        [$ops, $arg0, $arg1, $arg2, $locations, $functions, $lambdas, $classes, $initialisers, $methods] = $this->link();
        $end = count($ops);
        $tokens = [];
        foreach (self::BINARY as $opcode => [$type, $symbol]) {
            $tokens[$opcode] = new Token($type, $symbol);
        }
        $increment = new Token(Token::INCREMENT, '++');
        $decrement = new Token(Token::DECREMENT, '--');
        $local_names = $this->program->local_names;
        $global_names = $this->program->global_names;

        $pc = 0;
        $stack = [];
        $globals = [];
        $locals = [];
        // The running function ('' for the top level) and how many arguments it was passed
        $function = '';
        $argc = 0;
        // The closure whose call is running, or null
        $closure = null;
        // The object # is, in a method or a closure made in one, or null
        $receiver = null;
        // Callers' state, innermost last: [locals, return pc, function, argc, closure, receiver]
        $frames = [];
        // Installed try handlers, innermost last: [frame count, stack size, catch pc]
        $handlers = [];

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
                            // A closure's captured variables live in the closure, not the frame, so every
                            // call of it shares them; read them in place, never through a copy of the
                            // array, which would make writes copy what they hold
                        case 'LOAD_CAPTURED':
                            $slot = $arg0[$pc - 1];
                            if (isset($closure->captured[$slot]) || array_key_exists($slot, $closure->captured)) {
                                $stack[] = $closure->captured[$slot];
                            } else {
                                throw new Exception("Undefined variable: {$closure->lambda->captures[$slot]}");
                            }
                            break;
                        case 'STORE_CAPTURED':
                            $closure->captured[$arg0[$pc - 1]] = array_pop($stack);
                            break;
                        case 'LOAD_QUIET_CAPTURED':
                            $stack[] = $closure->captured[$arg0[$pc - 1]] ?? null;
                            break;
                        case 'PUSH':
                        case 'PUSH_STR':
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
                            $stack[] = is_string($left) && is_string($right)
                                ? $left.$right
                                : Values::binary($tokens['CONCAT'], $left, $right);
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
                        case 'PRINT':
                            echo Values::toString(array_pop($stack)).PHP_EOL;
                            break;
                        case 'NOT':
                            $value = array_pop($stack);
                            $stack[] = $value === false || ($value !== true && ! Values::isTruthy($value));
                            break;
                        case 'NEG':
                            $stack[] = Values::negate(array_pop($stack));
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
                            Values::store($closure->captured, $slot, $closure->lambda->captures[$slot], $keys, null, $value);
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
                        case 'GET_PROPERTY_QUIET':
                            $target = array_pop($stack);
                            $stack[] = $target === null ? null : Values::property($target, $arg0[$pc - 1], true);
                            break;
                        case 'GET_PROPERTY_EXISTING':
                            $target = array_pop($stack);
                            $stack[] = Values::propertyExisting($target, $arg0[$pc - 1]);
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
                            if (count($frames) === Values::MAX_CALL_DEPTH) {
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
                            [, $lambda, $map] = $lambdas[$index];
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
                            $made = FunctionValue::closure($lambda, $captured, $index, $receiver);
                            // $f = <lambda>: the closure's $f is the closure
                            if ($lambda->self !== null) {
                                $made->captured[$lambda->capture_names[$lambda->self]] = $made;
                            }
                            $stack[] = $made;
                            unset($made, $captured);
                            break;
                        case 'LOAD_THIS':
                            $stack[] = $receiver;
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
                            if ($target instanceof ObjectValue && isset($target->class->methods[$name]) && $name !== '_') {
                                $stack[] = $target;
                                $stack[] = $target->class->methods[$name];
                            } else {
                                $stack[] = Values::property($target, $name);
                                $stack[] = null;
                            }
                            break;
                        case 'CALL_METHOD':
                            $args = $this->popMany($stack, $arg0[$pc - 1]);
                            $definer = array_pop($stack);
                            $callee = array_pop($stack);
                            if ($definer === null) {
                                // A field holding a function, or whatever else the member was
                                goto call_value;
                            }
                            $name = $arg1[$pc - 1];
                            [$entry, $arity] = $methods["{$definer->name}.{$name}"];
                            if (! Builtins::fitsArity($arity, count($args))) {
                                throw new Exception(Builtins::arityError("Method {$definer->name}.{$name}", $arity, count($args)));
                            }
                            if (count($frames) === Values::MAX_CALL_DEPTH) {
                                throw new Exception('Maximum call depth of '.Values::MAX_CALL_DEPTH." exceeded calling {$definer->name}.{$name}");
                            }
                            $frames[] = [$locals, $pc, $function, $argc, $closure, $receiver];
                            [$locals, $argc, $function, $closure, $receiver, $pc] = [$args, count($args), "{$definer->name}.{$name}", null, $callee, $entry];
                            break;
                        case 'NEW':
                            $args = $this->popMany($stack, $arg1[$pc - 1]);
                            $callee = $classes[$arg0[$pc - 1]];
                            goto construct;
                        case 'CALL_CONSTRUCTOR':
                            // The constructor runs with the arguments the object's initialiser was given
                            if (count($frames) === Values::MAX_CALL_DEPTH) {
                                throw new Exception('Maximum call depth of '.Values::MAX_CALL_DEPTH." exceeded calling {$arg0[$pc - 1]}._");
                            }
                            $frames[] = [$locals, $pc, $function, $argc, $closure, $receiver];
                            $function = "{$arg0[$pc - 1]}._";
                            $pc = $methods[$function][0];
                            break;
                        case 'PUSH_CLASS':
                            $stack[] = $classes[$arg0[$pc - 1]];
                            break;
                        case 'CALL_VALUE':
                            $args = $this->popMany($stack, $arg0[$pc - 1]);
                            $callee = array_pop($stack);
                            call_value:
                            if ($callee instanceof ClassValue) {
                                if ($callee->isAbstract()) {
                                    throw new Exception("Cannot construct abstract class {$callee->name}");
                                }
                                if (! Builtins::fitsArity($callee->arity, count($args))) {
                                    throw new Exception(Builtins::arityError("Class {$callee->name}", $callee->arity, count($args)));
                                }
                                construct:
                                // One frame sets the field defaults and runs the constructor, with the object as receiver
                                if (count($frames) === Values::MAX_CALL_DEPTH) {
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
                                $name === null => $callee->lambda->arity,
                                $callee->class !== null => $methods["{$callee->class->name}.{$name}"][1],
                                $builtin => Builtins::ARITIES[$name],
                                default => $functions[$name][1],
                            };
                            if (! Builtins::fitsArity($arity, count($args))) {
                                throw new Exception(Builtins::arityError($callee->title(), $arity, count($args)));
                            }
                            if ($builtin) {
                                $stack[] = $this->builtins->call($name, $args);
                                break;
                            }
                            // The same frame push as CALL, with the arguments already popped; a closure's
                            // body reads its captured variables from the closure
                            if (count($frames) === Values::MAX_CALL_DEPTH) {
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
                                $pc = $methods[$function][0];
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
                                        $stack[] = $this->builtins->call($name, [$first]);
                                    }
                                    break;
                                case 2:
                                    $second = array_pop($stack);
                                    $first = array_pop($stack);
                                    $stack[] = $this->builtins->call($name, [$first, $second]);
                                    break;
                                default:
                                    $stack[] = $this->builtins->call($name, $this->popMany($stack, $arg1[$pc - 1]));
                            }
                            break;
                        case 'TRY':
                            $handlers[] = [count($frames), count($stack), $arg0[$pc - 1]];
                            break;
                        case 'END_TRY':
                            array_pop($handlers);
                            break;
                        case 'HALT':
                            return;
                        default:
                            $opcode = $ops[$pc - 1];
                            if (! isset($tokens[$opcode])) {
                                throw new Exception("Unknown instruction: {$opcode}");
                            }
                            $right = array_pop($stack);
                            $stack[] = Values::binary($tokens[$opcode], array_pop($stack), $right);
                    }
                }

                return;
            } catch (ExitSignal $e) {
                // exit() is not an error: no handler sees it
                throw $e;
            } catch (Exception $e) {
                $error = $this->locate($e, $locations[$pc - 1]);
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
                $stack[] = $error->toMap();
                $pc = $catch_pc;
            }
        }
    }

    /**
     * Prepare the program to run: simplify, resolve labels, and split into parallel arrays
     *
     * - STORE x; LOAD x; POP (an assignment used as a statement) becomes STORE x.
     * - LABEL instructions are dropped; JMP, JZ and TRY get the position to jump to, and
     *   CALL gets the position, the argument count and the function name (for the call
     *   depth error).
     * - Each instruction's opcode and first three arguments go into their own arrays, so
     *   the loop reads what it needs without unpacking an instruction each time, and its
     *   [file, line] into $locations, only read when there is an error.
     * - Each user function's entry position and arity go into $functions, for CALL_VALUE, and
     *   each lambda's entry position, node and capture map into $lambdas.
     * - The classes are built as values into $classes, with the entry of the code that makes
     *   each one's objects in $initialisers and each method's entry and arity in $methods,
     *   keyed "Class.name".
     *
     * @return array{0: list<string>, 1: list<mixed>, 2: list<mixed>, 3: list<mixed>, 4: list<array{0: string|null, 1: int|null}>, 5: array<string, array{0: int, 1: int|array{0: int, 1: int}}>, 6: list<array{0: int, 1: LambdaAST, 2: list<array{0: bool, 1: int, 2: int}>}>, 7: array<string, ClassValue>, 8: array<string, int>, 9: array<string, array{0: int, 1: int|array{0: int, 1: int}}>}
     */
    private function link(): array
    {
        $code = [];
        foreach ($this->program->instructions as $instruction) {
            $code[] = $instruction;
            $n = count($code);
            if ($n >= 3 && $instruction[0] === 'POP'
                && in_array($code[$n - 3][0], ['STORE', 'STORE_GLOBAL'], true)
                && $code[$n - 2][0] === ($code[$n - 3][0] === 'STORE' ? 'LOAD' : 'LOAD_GLOBAL')
                && $code[$n - 2][1] === $code[$n - 3][1]) {
                array_splice($code, -2);
            }
        }

        $positions = [];
        $linked = [];
        foreach ($code as $instruction) {
            if ($instruction[0] === 'LABEL') {
                $positions[$instruction[1][0]] = count($linked);
            } else {
                $linked[] = $instruction;
            }
        }

        $ops = $arg0 = $arg1 = $arg2 = $locations = [];
        foreach ($linked as [$opcode, $args, $file, $line]) {
            if (in_array($opcode, ['JMP', 'JZ', 'JNN', 'TRY'], true)) {
                $args[0] = $positions[$args[0]];
            } elseif ($opcode === 'CALL') {
                $args = [$positions[$args[0]], $args[1], substr($args[0], 3)];
            } elseif (str_starts_with($opcode, 'SET_PATH')) {
                $args[0] = self::parsePath($args[0]);
            }
            $ops[] = $opcode;
            $arg0[] = $args[0] ?? null;
            $arg1[] = $args[1] ?? null;
            $arg2[] = $args[2] ?? null;
            $locations[] = [$file, $line];
        }

        $functions = [];
        foreach ($this->program->functions as $name => $arity) {
            $functions[$name] = [$positions["FN_{$name}"], $arity];
        }
        $lambdas = [];
        foreach ($this->program->lambdas as $index => [$lambda, $map]) {
            $lambdas[$index] = [$positions["LAMBDA_{$index}"], $lambda, $map];
        }
        $classes = ClassValue::build($this->program->classes);
        $initialisers = [];
        $methods = [];
        foreach ($this->program->classes as $name => $class) {
            $initialisers[$name] = $positions["NEW_{$name}"];
            foreach ($class->methods as $method) {
                if (! $method->abstract) {
                    $methods["{$name}.{$method->name}"] = [$positions["METHOD_{$name}.{$method->name}"], $method->arity];
                }
            }
        }

        return [$ops, $arg0, $arg1, $arg2, $locations, $functions, $lambdas, $classes, $initialisers, $methods];
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
     * Pop the operands of SET_PATH: the value, and the keys pushed before it, as the steps Values::store() takes
     *
     * @param  array  $stack  The value stack, by reference
     * @param  array{0: int, 1: list<PropertyStep|string|null>|null}  $path  The parsed path
     * @return array{0: array, 1: mixed} The steps, outermost first, and the value
     */
    private function pathOperands(array &$stack, array $path): array
    {
        [$count, $steps] = $path;
        $value = array_pop($stack);
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
     * Give an error the location of the instruction that raised it, as the interpreter's visit() does
     *
     * @param  Exception  $error  The error
     * @param  array{0: string|null, 1: int|null}  $location  The [file, line] of the instruction that raised it
     */
    private function locate(Exception $error, array $location): Exception
    {
        [$file, $line] = $location;
        if ($line === null || ($error instanceof GazLangError && $error->line_number !== null)) {
            return $error;
        }

        return $error instanceof GazLangError
            ? new GazLangError($error->reason, $file, $line, $error->show_location)
            : new GazLangError($error->getMessage(), $file, $line);
    }
}
