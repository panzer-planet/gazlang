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
     * @throws GazLangError If an error isn't caught by a try
     */
    public function run(): void
    {
        $code = $this->link();
        $end = count($code);
        $tokens = [];
        foreach (self::BINARY as $opcode => [$type, $symbol]) {
            $tokens[$opcode] = new Token($type, $symbol);
        }
        $increment = new Token(Token::INCREMENT, '++');
        $decrement = new Token(Token::DECREMENT, '--');

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
                    [$opcode, $args] = $code[$pc++];

                    switch ($opcode) {
                        case 'PUSH':
                        case 'PUSH_STR':
                            $stack[] = $args[0];
                            break;
                        case 'POP':
                            array_pop($stack);
                            break;
                        case 'LOAD':
                            if (! array_key_exists($args[0], $locals)) {
                                throw new Exception('Undefined variable: '.$this->program->local_names[$function][$args[0]]);
                            }
                            $stack[] = $locals[$args[0]];
                            break;
                        case 'STORE':
                            $locals[$args[0]] = array_pop($stack);
                            break;
                        case 'LOAD_GLOBAL':
                            if (! array_key_exists($args[0], $globals)) {
                                throw new Exception('Undefined variable: '.$this->program->global_names[$args[0]]);
                            }
                            $stack[] = $globals[$args[0]];
                            break;
                        case 'STORE_GLOBAL':
                            $globals[$args[0]] = array_pop($stack);
                            break;
                        case 'PRINT':
                            echo Values::toString(array_pop($stack)).PHP_EOL;
                            break;
                        case 'NOT':
                            $stack[] = ! Values::isTruthy(array_pop($stack));
                            break;
                        case 'NEG':
                            $stack[] = Values::negate(array_pop($stack));
                            break;
                        case 'INC':
                        case 'DEC':
                            $stack[] = Values::step(array_pop($stack), $opcode === 'INC' ? $increment : $decrement);
                            break;
                        case 'JMP':
                            $pc = $args[0];
                            break;
                        case 'JZ':
                            if (! Values::isTruthy(array_pop($stack))) {
                                $pc = $args[0];
                            }
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
                            $stack[] = Values::index(array_pop($stack), $index);
                            break;
                        case 'INDEX_GET_EXISTING':
                            $index = array_pop($stack);
                            $stack[] = Values::indexExisting(array_pop($stack), $index);
                            break;
                        case 'SET_PATH':
                        case 'APPEND_PATH':
                            [$keys, $value] = $this->pathOperands($stack, $args[0], $opcode === 'APPEND_PATH');
                            Values::store($locals, $args[1], $this->program->local_names[$function][$args[1]], $keys, null, $value);
                            $stack[] = $value;
                            break;
                        case 'SET_PATH_GLOBAL':
                        case 'APPEND_PATH_GLOBAL':
                            [$keys, $value] = $this->pathOperands($stack, $args[0], $opcode === 'APPEND_PATH_GLOBAL');
                            Values::store($globals, $args[1], $this->program->global_names[$args[1]], $keys, null, $value);
                            $stack[] = $value;
                            break;
                        case 'FOREACH_CHECK':
                            $iterable = $stack[array_key_last($stack)];
                            if (! is_array($iterable)) {
                                throw new Exception('foreach expects an array, got '.get_debug_type($iterable));
                            }
                            break;
                        case 'CALL':
                            [$target, $count, $name] = $args;
                            if (count($frames) === Values::MAX_CALL_DEPTH) {
                                throw new Exception('Maximum call depth of '.Values::MAX_CALL_DEPTH." exceeded calling {$name}");
                            }
                            $frames[] = [$locals, $pc, $function, $argc];
                            $locals = $count === 0 ? [] : array_splice($stack, -$count);
                            [$function, $argc, $pc] = [$name, $count, $target];
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
                            $call_args = $args[1] === 0 ? [] : array_splice($stack, -$args[1]);
                            $stack[] = $this->builtins->call($args[0], $call_args);
                            break;
                        case 'TRY':
                            $handlers[] = [count($frames), count($stack), $args[0]];
                            break;
                        case 'END_TRY':
                            array_pop($handlers);
                            break;
                        case 'HALT':
                            return;
                        default:
                            if (! isset($tokens[$opcode])) {
                                throw new Exception("Unknown instruction: {$opcode}");
                            }
                            $right = array_pop($stack);
                            $stack[] = Values::binary($tokens[$opcode], array_pop($stack), $right);
                    }
                }

                return;
            } catch (Exception $e) {
                $error = $this->locate($e, $code[$pc - 1]);
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
     * Resolve labels to instruction positions and drop the LABEL instructions
     *
     * JMP, JZ and TRY get the position to jump to; CALL gets [position, argument count,
     * function name], the name for the call depth error.
     *
     * @return list<array{0: string, 1: array, 2: string|null, 3: int|null}> The linked instructions
     */
    private function link(): array
    {
        $positions = [];
        $code = [];
        foreach ($this->program->instructions as $instruction) {
            if ($instruction[0] === 'LABEL') {
                $positions[$instruction[1][0]] = count($code);
            } else {
                $code[] = $instruction;
            }
        }

        foreach ($code as &$instruction) {
            if (in_array($instruction[0], ['JMP', 'JZ', 'TRY'], true)) {
                $instruction[1][0] = $positions[$instruction[1][0]];
            } elseif ($instruction[0] === 'CALL') {
                $instruction[1] = [$positions[$instruction[1][0]], $instruction[1][1], substr($instruction[1][0], 3)];
            }
        }

        return $code;
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
     * @param  array{0: string, 1: array, 2: string|null, 3: int|null}  $instruction  The instruction that raised it
     */
    private function locate(Exception $error, array $instruction): Exception
    {
        [, , $file, $line] = $instruction;
        if ($line === null || ($error instanceof GazLangError && $error->line_number !== null)) {
            return $error;
        }

        return $error instanceof GazLangError
            ? new GazLangError($error->reason, $file, $line, $error->show_location)
            : new GazLangError($error->getMessage(), $file, $line);
    }
}
