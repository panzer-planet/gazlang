<?php

namespace GazLang\VM;

use Exception;
use GazLang\CodeGenerator\Program;
use GazLang\GazLangError;
use GazLang\Lexer\Token;
use GazLang\Runtime\Builtins;
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
        'ADD_OR_CONCAT' => [Token::PLUS, '+'],
        'SUB' => [Token::MINUS, '-'],
        'MUL' => [Token::MULTIPLY, '*'],
        'DIV' => [Token::DIVIDE, '/'],
        'MOD' => [Token::MODULO, '%'],
        'EQUALS' => [Token::EQUALS, '=='],
        'NOT_EQUALS' => [Token::NOT_EQUALS, '!='],
        'STRICT_EQUALS' => [Token::STRICT_EQUALS, '==='],
        'STRICT_NOT_EQUALS' => [Token::STRICT_NOT_EQUALS, '!=='],
        'LT' => [Token::LESS_THAN, '<'],
        'LE' => [Token::LESS_EQUALS, '<='],
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
        [$ops, $arg0, $arg1, $arg2, $locations] = $this->link();
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
        // Callers' state, innermost last: [locals, return pc, function, argc]
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
                        case 'ADD_OR_CONCAT':
                            $right = array_pop($stack);
                            $left = array_pop($stack);
                            // An int result that overflows is a float in PHP, which Values reports as an error
                            // @phpstan-ignore booleanAnd.rightAlwaysTrue
                            if (is_int($left) && is_int($right) && is_int($result = $left + $right)) {
                                $stack[] = $result;
                            } else {
                                $stack[] = Values::binary($tokens['ADD_OR_CONCAT'], $left, $right);
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
                        case 'EQUALS':
                        case 'NOT_EQUALS':
                            $opcode = $ops[$pc - 1];
                            $right = array_pop($stack);
                            $left = array_pop($stack);
                            if (is_int($left) && is_int($right)) {
                                $stack[] = match ($opcode) {
                                    'LT' => $left < $right,
                                    'LE' => $left <= $right,
                                    'GT' => $left > $right,
                                    'GE' => $left >= $right,
                                    'EQUALS' => $left === $right,
                                    'NOT_EQUALS' => $left !== $right,
                                    default => throw new Exception("Unknown instruction: {$opcode}"),
                                };
                            } else {
                                $stack[] = Values::binary($tokens[$opcode], $left, $right);
                            }
                            break;
                        case 'STRICT_EQUALS':
                            // Values::binary compares === before any conversion, for every type
                            $right = array_pop($stack);
                            $stack[] = array_pop($stack) === $right;
                            break;
                        case 'STRICT_NOT_EQUALS':
                            $right = array_pop($stack);
                            $stack[] = array_pop($stack) !== $right;
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
                        case 'ARRAY_SET':
                            $value = array_pop($stack);
                            $key = Values::arrayKey(array_pop($stack));
                            $stack[array_key_last($stack)][$key] = $value;
                            break;
                        case 'INDEX_GET':
                            $index = array_pop($stack);
                            $target = array_pop($stack);
                            $stack[] = is_array($target) && (is_int($index) || is_string($index))
                                ? $target[$index] ?? null
                                : Values::index($target, $index);
                            break;
                        case 'INDEX_GET_EXISTING':
                            $index = array_pop($stack);
                            $stack[] = Values::indexExisting(array_pop($stack), $index);
                            break;
                        case 'SET_PATH':
                        case 'APPEND_PATH':
                            [$keys, $value] = $this->pathOperands($stack, $arg0[$pc - 1], $ops[$pc - 1] === 'APPEND_PATH');
                            $slot = $arg1[$pc - 1];
                            Values::store($locals, $slot, $local_names[$function][$slot], $keys, null, $value);
                            $stack[] = $value;
                            break;
                        case 'SET_PATH_GLOBAL':
                        case 'APPEND_PATH_GLOBAL':
                            [$keys, $value] = $this->pathOperands($stack, $arg0[$pc - 1], $ops[$pc - 1] === 'APPEND_PATH_GLOBAL');
                            $slot = $arg1[$pc - 1];
                            Values::store($globals, $slot, $global_names[$slot], $keys, null, $value);
                            $stack[] = $value;
                            break;
                        case 'FOREACH_CHECK':
                            $iterable = $stack[array_key_last($stack)];
                            if (! is_array($iterable)) {
                                throw new Exception('foreach expects an array, got '.get_debug_type($iterable));
                            }
                            break;
                        case 'CALL':
                            if (count($frames) === Values::MAX_CALL_DEPTH) {
                                throw new Exception('Maximum call depth of '.Values::MAX_CALL_DEPTH." exceeded calling {$arg2[$pc - 1]}");
                            }
                            $frames[] = [$locals, $pc, $function, $argc];
                            $argc = $arg1[$pc - 1];
                            $locals = $argc === 0 ? [] : array_splice($stack, -$argc);
                            $function = $arg2[$pc - 1];
                            $pc = $arg0[$pc - 1];
                            break;
                        case 'ARGC':
                            $stack[] = $argc;
                            break;
                        case 'RET':
                            // Handlers installed by this call are gone with its frame
                            while ($handlers !== [] && $handlers[array_key_last($handlers)][0] === count($frames)) {
                                array_pop($handlers);
                            }
                            [$locals, $pc, $function, $argc] = array_pop($frames);
                            break;
                        case 'CALL_BUILTIN':
                            $count = $arg1[$pc - 1];
                            $call_args = $count === 0 ? [] : array_splice($stack, -$count);
                            $stack[] = $this->builtins->call($arg0[$pc - 1], $call_args);
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
            } catch (Exception $e) {
                $error = $this->locate($e, $locations[$pc - 1]);
                if (! $error instanceof GazLangError || $handlers === []) {
                    throw $error;
                }

                // Unwind to the innermost try: drop the calls made inside it and whatever
                // the failed expression left on the stack, then run its catch block
                [$frame_count, $stack_size, $catch_pc] = array_pop($handlers);
                while (count($frames) > $frame_count) {
                    [$locals, , $function, $argc] = array_pop($frames);
                }
                array_splice($stack, $stack_size);
                $stack[] = ['message' => $error->reason, 'file' => $error->path, 'line' => $error->line_number];
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
     *
     * @return array{0: list<string>, 1: list<mixed>, 2: list<mixed>, 3: list<mixed>, 4: list<array{0: string|null, 1: int|null}>}
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
            if (in_array($opcode, ['JMP', 'JZ', 'TRY'], true)) {
                $args[0] = $positions[$args[0]];
            } elseif ($opcode === 'CALL') {
                $args = [$positions[$args[0]], $args[1], substr($args[0], 3)];
            }
            $ops[] = $opcode;
            $arg0[] = $args[0] ?? null;
            $arg1[] = $args[1] ?? null;
            $arg2[] = $args[2] ?? null;
            $locations[] = [$file, $line];
        }

        return [$ops, $arg0, $arg1, $arg2, $locations];
    }

    /**
     * Pop the operands of SET_PATH/APPEND_PATH: the value, and the keys pushed before it
     *
     * @param  array  $stack  The value stack, by reference
     * @param  int  $count  How many keys were pushed
     * @param  bool  $append  Whether to add the null key that means append
     * @return array{0: array, 1: mixed} The keys, outermost first, and the value
     */
    private function pathOperands(array &$stack, int $count, bool $append): array
    {
        $value = array_pop($stack);
        $keys = $count === 0 ? [] : array_map(Values::arrayKey(...), array_splice($stack, -$count));
        if ($append) {
            $keys[] = null;
        }

        return [$keys, $value];
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
