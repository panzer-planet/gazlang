<?php

namespace GazLang\CodeGenerator;

use GazLang\GazLangError;
use GazLang\Lexer\Lexer;
use GazLang\Runtime\Builtins;
use GazLang\Runtime\MapValue;
use GazLang\Runtime\Values;

/**
 * A compiled program: one block of code per function, plus the names and records the VM needs
 *
 * A block is the top level code, a function (a method is a function named "Class.name",
 * which no function name can be), a class (its record and the code that makes one of its
 * objects) or a lambda. Labels are scoped to their block, so a block can be read on its own
 * and one extra instruction doesn't move every later jump. VM::link() concatenates the
 * blocks, the top level first, and resolves the labels.
 *
 * The same program is also a file: write() prints it and read() reads it back, which is what
 * `gazlang -c` prints and what a GazLang compiler will write. See docs/bytecode.md.
 */
final class Program
{
    /**
     * The format version write() writes and read() accepts, with no compatibility promise until the bootstrap
     */
    public const VERSION = 1;

    /**
     * The first line of every bytecode file, with the version after it
     */
    public const MAGIC = 'GAZLANG BYTECODE';

    /**
     * Every instruction: its arguments, and what it does to the stack as [pops, pushes]
     *
     * The argument kinds say how to read and check each one: an int (count, lambda, or a
     * slot of this frame, the globals or the running closure), a name that must be there (a
     * function of the program, a callable that may also be a builtin, a builtin, a class, or
     * a member, which is only checked when it runs), a label in the same block, a write path,
     * or a value written as a GazLang literal, which is the rest of the line. A count in
     * "pops" means that argument's value, and "path" the keys the path takes from the stack.
     * docs/bytecode.md describes each instruction; the test keeps the three in step.
     *
     * @var array<string, array{0: list<string>, 1: array{0: int|string, 1: int}}>
     */
    public const INSTRUCTIONS = [
        'LABEL' => [['label'], [0, 0]],
        'PUSH' => [['value'], [0, 1]],
        'POP' => [[], [1, 0]],
        'PRINT' => [[], [1, 0]],
        'LOAD' => [['slot'], [0, 1]],
        'LOAD_QUIET' => [['slot'], [0, 1]],
        'STORE' => [['slot'], [1, 0]],
        'LOAD_GLOBAL' => [['global'], [0, 1]],
        'LOAD_QUIET_GLOBAL' => [['global'], [0, 1]],
        'STORE_GLOBAL' => [['global'], [1, 0]],
        'LOAD_CAPTURED' => [['capture'], [0, 1]],
        'LOAD_QUIET_CAPTURED' => [['capture'], [0, 1]],
        'STORE_CAPTURED' => [['capture'], [1, 0]],
        'ADD' => [[], [2, 1]],
        'SUB' => [[], [2, 1]],
        'MUL' => [[], [2, 1]],
        'DIV' => [[], [2, 1]],
        'MOD' => [[], [2, 1]],
        'CONCAT' => [[], [2, 1]],
        'EQUALS' => [[], [2, 1]],
        'NOT_EQUALS' => [[], [2, 1]],
        'LT' => [[], [2, 1]],
        'LE' => [[], [2, 1]],
        'GT' => [[], [2, 1]],
        'GE' => [[], [2, 1]],
        'CMP' => [[], [2, 1]],
        'NOT' => [[], [1, 1]],
        'NEG' => [[], [1, 1]],
        'INC' => [[], [1, 1]],
        'DEC' => [[], [1, 1]],
        'JMP' => [['label'], [0, 0]],
        'JZ' => [['label'], [1, 0]],
        'JNN' => [['label'], [1, 0]],
        'NEW_ARRAY' => [[], [0, 1]],
        'ARRAY_PUSH' => [[], [2, 1]],
        'NEW_MAP' => [[], [0, 1]],
        'MAP_SET' => [[], [3, 1]],
        'KEY_CHECK' => [[], [1, 1]],
        'FOREACH_CHECK' => [[], [1, 1]],
        'DESTRUCTURE' => [['count'], [1, 1]],
        'INDEX_GET' => [[], [2, 1]],
        'INDEX_GET_QUIET' => [[], [2, 1]],
        'INDEX_GET_EXISTING' => [[], [2, 1]],
        'SET_PATH' => [['path', 'slot'], ['path', 1]],
        'SET_PATH_GLOBAL' => [['path', 'global'], ['path', 1]],
        'SET_PATH_CAPTURED' => [['path', 'capture'], ['path', 1]],
        'SET_PATH_THIS' => [['path'], ['path', 1]],
        'CALL' => [['function', 'count'], ['count', 1]],
        'CALL_BUILTIN' => [['builtin', 'count'], ['count', 1]],
        'CALL_VALUE' => [['count'], ['count+1', 1]],
        'ARGC' => [[], [0, 1]],
        'RET' => [[], [1, 0]],
        'PUSH_FN' => [['callable'], [0, 1]],
        'MAKE_CLOSURE' => [['lambda'], [0, 1]],
        'PUSH_CLASS' => [['class'], [0, 1]],
        'NEW' => [['class', 'count'], ['count', 1]],
        'CALL_CONSTRUCTOR' => [['class'], [0, 1]],
        'CALL_PARENT' => [['class', 'member', 'count'], ['count', 1]],
        'BIND_PARENT' => [['class', 'member'], [0, 1]],
        'LOAD_THIS' => [[], [0, 1]],
        'LOAD_FIELD' => [['member'], [0, 1]],
        'SET_FIELD' => [['member'], [1, 1]],
        'GET_PROPERTY' => [['member'], [1, 1]],
        'GET_PROPERTY_QUIET' => [['member'], [1, 1]],
        'GET_PROPERTY_EXISTING' => [['member'], [1, 1]],
        'GET_METHOD' => [['member'], [1, 2]],
        'CALL_METHOD' => [['count', 'member'], ['count+2', 1]],
        'TRY' => [['label'], [0, 0]],
        'END_TRY' => [[], [0, 0]],
        'CATCH_MATCH' => [['class', 'label'], [1, 1]],
        'CATCH_VALUE' => [[], [1, 1]],
        'RETHROW' => [[], [1, 0]],
        'HALT' => [[], [0, 0]],
    ];

    /**
     * @var list<array{kind: string, locals: list<string>, code: list<array{0: string, 1: array, 2: string|null, 3: int|null}>, name?: string, index?: int, arity?: int|array{0: int, 1: int}, parent?: string|null, abstract?: bool, fields?: array<string, string>, methods?: array<string, string>, captures?: list<string>, self?: int|null, map?: list<array{0: bool, 1: int, 2: int}>}>
     *                                                                                                                                                                                                                                                                                                                                                                                        Every block, the top level first, then the functions, then each class with its methods, then the lambdas. Each holds
     *                                                                                                                                                                                                                                                                                                                                                                                        its instructions as [opcode, arguments, file, line], the location being the source it came from
     */
    public $blocks;

    /**
     * @var list<string> The variable name in each global slot
     */
    public $global_names;

    /**
     * @var array<string, list<string>> The variable name in each local slot, by block key (see key())
     */
    public $local_names = [];

    /**
     * @var array<string, int|array{0: int, 1: int}> Each function's and method's arity, for calls on function values
     */
    public $functions = [];

    /**
     * @var list<array{arity: int|array{0: int, 1: int}, captures: list<string>, self: int|null, map: list<array{0: bool, 1: int, 2: int}>}>
     *                                                                                                                                       Each lambda's record, with its capture map: [from the enclosing closure rather than the frame, slot or index there,
     *                                                                                                                                       index in the new closure]
     */
    public $lambdas = [];

    /**
     * @var array<string, array{parent: string|null, abstract: bool, fields: array<string, string>, methods: array<string, string>}>
     *                                                                                                                               Each class's record: its parent, whether it is abstract, every field with the class that declares it, and every
     *                                                                                                                               method with the class whose version runs. The field defaults are code, in the class's block
     */
    public $classes = [];

    /**
     * Constructor
     *
     * @param  list<array<string, mixed>>  $blocks  The blocks, the top level first
     * @param  list<string>  $global_names  Global slot names
     */
    public function __construct(array $blocks, array $global_names)
    {
        $this->blocks = $blocks;
        $this->global_names = $global_names;

        foreach ($blocks as $block) {
            $this->local_names[self::key($block)] = $block['locals'];
            match ($block['kind']) {
                'fn' => $this->functions[$block['name']] = $block['arity'],
                'class' => $this->classes[$block['name']] = [
                    'parent' => $block['parent'], 'abstract' => $block['abstract'],
                    'fields' => $block['fields'], 'methods' => $block['methods'],
                ],
                'lambda' => $this->lambdas[$block['index']] = [
                    'arity' => $block['arity'], 'captures' => $block['captures'],
                    'self' => $block['self'], 'map' => $block['map'],
                ],
                default => null,
            };
        }
    }

    /**
     * How a block is keyed: '' for the top level, the name of a function or method, "new Class" for a class, "->n" for a lambda
     *
     * @param  array<string, mixed>  $block  The block
     */
    public static function key(array $block): string
    {
        return match ($block['kind']) {
            'fn' => $block['name'],
            'class' => "new {$block['name']}",
            'lambda' => "->{$block['index']}",
            default => '',
        };
    }

    /**
     * The program as a bytecode file
     *
     * @return string The file, ending in a newline
     */
    public function __toString(): string
    {
        return $this->write();
    }

    /**
     * Write the program as a bytecode file
     *
     * Paths are written relative to the directory of the main source file, so bytecode saved
     * next to its source reports the same paths as running the source does. Names in angle
     * brackets, like <builtin>, are not paths and are written as they are.
     *
     * @param  string|null  $main  The main source file, as the user named it; null for piped or inline source
     * @return string The file, ending in a newline
     */
    public function write(?string $main = null): string
    {
        $base = self::absolute($main === null ? '.' : dirname($main));
        $lines = [self::MAGIC.' '.self::VERSION, implode(' ', ['globals', ...$this->global_names])];

        foreach ($this->blocks as $block) {
            $lines[] = '';
            array_push($lines, ...self::header($block));
            $lines[] = implode(' ', ['locals', ...$block['locals']]);

            [$file, $line] = [null, null];
            foreach ($block['code'] as [$opcode, $args, $at_file, $at_line]) {
                // A label is a position, not an instruction, so it has no location of its own
                if ($opcode !== 'LABEL' && $at_line !== null && ($at_file !== $file || $at_line !== $line)) {
                    [$file, $line] = [$at_file, $at_line];
                    $lines[] = $file === null ? "@ {$line}" : '@ '.Lexer::quote(self::relative($file, $base))." {$line}";
                }
                $kinds = self::INSTRUCTIONS[$opcode][0];
                foreach ($args as $i => $argument) {
                    $args[$i] = $kinds[$i] === 'value' ? self::value($argument) : (string) $argument;
                }
                $lines[] = implode(' ', [$opcode, ...$args]);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The header lines of a block
     *
     * @param  array<string, mixed>  $block  The block
     * @return list<string> The lines
     */
    private static function header(array $block): array
    {
        if ($block['kind'] === 'fn') {
            return ['fn '.$block['name'].' '.implode(' ', Builtins::bounds($block['arity']))];
        }
        if ($block['kind'] === 'class') {
            $lines = [($block['abstract'] ? 'abstract class ' : 'class ').$block['name'].($block['parent'] === null ? '' : " extends {$block['parent']}")];
            foreach ($block['fields'] as $field => $declarer) {
                $lines[] = "field {$field} {$declarer}";
            }
            foreach ($block['methods'] as $method => $definer) {
                $lines[] = "method {$method} {$definer}";
            }

            return $lines;
        }
        if ($block['kind'] === 'lambda') {
            $lines = ['lambda '.$block['index'].' '.implode(' ', Builtins::bounds($block['arity']))];
            foreach ($block['map'] as [$from_closure, $outer, $inner]) {
                $lines[] = 'capture '.$block['captures'][$inner].($from_closure ? ' captured ' : ' local ').$outer;
            }
            if ($block['self'] !== null) {
                $lines[] = 'self '.$block['captures'][$block['self']];
            }

            return $lines;
        }

        return ['top'];
    }

    /**
     * A value argument as a GazLang literal, which reads back as the same value
     *
     * It is the only argument that can hold spaces, so an instruction that takes one takes
     * it last, as the rest of the line; every other argument is a name, a label or a number.
     *
     * @param  mixed  $value  The value: a scalar, or a list or map built at compile time
     */
    private static function value($value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_float($value) => Lexer::format_float($value),
            is_string($value) => Lexer::quote($value),
            is_array($value) || $value instanceof MapValue => Values::toString($value),
            default => (string) $value,
        };
    }

    /**
     * Read a bytecode file
     *
     * Paths are resolved against the directory the file is in, as the user named it, and
     * shown the way the parser shows them: relative to the working directory when they are
     * under it. The instructions, labels, names and stack are checked as they are read, so a
     * program that loads is one the VM can run.
     *
     * @param  string  $text  The file
     * @param  string|null  $path  The file's own path, for resolving source paths and locating load errors
     * @return self The program
     *
     * @throws GazLangError If the file is not bytecode this version can run
     */
    public static function read(string $text, ?string $path = null): self
    {
        return (new BytecodeReader($text, $path))->read();
    }

    /**
     * A path as it is written in a bytecode file: relative to the main source file's directory
     *
     * @param  string  $file  The path, as the parser shows it
     * @param  string  $base  The main source file's directory, absolute and normalised
     */
    private static function relative(string $file, string $base): string
    {
        if (str_starts_with($file, '<')) {
            return $file;
        }

        $from = self::segments($base);
        $to = self::segments(self::absolute($file));
        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return implode('/', [...array_fill(0, count($from), '..'), ...$to]);
    }

    /**
     * A path made absolute and normalised, without asking the file system: it may not exist
     *
     * @param  string  $path  The path
     */
    public static function absolute(string $path): string
    {
        $parts = [];
        foreach (self::segments(str_starts_with($path, '/') ? $path : getcwd().'/'.$path) as $part) {
            // Nothing is above the root, so a .. there is dropped
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.') {
                $parts[] = $part;
            }
        }

        return '/'.implode('/', $parts);
    }

    /**
     * A path's parts, without the empty ones a leading, trailing or doubled slash gives
     *
     * @param  string  $path  The path
     * @return list<string> The parts
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', $path), fn (string $part) => $part !== ''));
    }
}
