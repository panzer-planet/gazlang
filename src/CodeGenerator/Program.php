<?php

namespace GazLang\CodeGenerator;

use GazLang\Lexer\Lexer;
use GazLang\Runtime\MapValue;
use GazLang\Runtime\Values;

/**
 * Code generated for the stack VM: instructions, plus the names the VM needs for error messages
 */
final class Program
{
    /**
     * @var list<array{0: string, 1: array, 2: string|null, 3: int|null}> Each instruction as
     *                                                                    [opcode, arguments, file, line], the location being the source it came from
     */
    public $instructions;

    /**
     * @var array<string, list<string>> The variable name in each local slot, for the top level (''), each function (by name),
     *                                  lambda ("->n"), method ("Class.name") and object initialiser ("new Class")
     */
    public $local_names;

    /**
     * @var list<string> The variable name in each global slot
     */
    public $global_names;

    /**
     * @var array<string, int|array{0: int, 1: int}> Each user function's arity, for calls on function values; a method's is
     *                                               keyed "Class.name", which no function name can be
     */
    public $functions;

    /**
     * @var list<array{arity: int|array{0: int, 1: int}, captures: list<string>, self: int|null, map: list<array{0: bool, 1: int, 2: int}>}>
     *                                                                                                                                       Each lambda as a record (its body is at LABEL LAMBDA_n), with its capture map: [from the enclosing closure rather
     *                                                                                                                                       than the frame, slot or index there, index in the new closure]
     */
    public $lambdas;

    /**
     * @var array<string, array{parent: string|null, abstract: bool, fields: array<string, string>, methods: array<string, string>}>
     *                                                                                                                               Each class as a record: its parent, whether it is abstract, every field with the class that declares it and every
     *                                                                                                                               method with the class whose version runs. Methods are at LABEL METHOD_Class.name and the code that makes an object
     *                                                                                                                               at LABEL NEW_Class; the field defaults are part of that code
     */
    public $classes;

    /**
     * Constructor
     *
     * @param  list<array{0: string, 1: array, 2: string|null, 3: int|null}>  $instructions  The instructions
     * @param  array<string, list<string>>  $local_names  Local slot names by frame
     * @param  list<string>  $global_names  Global slot names
     * @param  array<string, int|array{0: int, 1: int}>  $functions  Each function's and method's arity
     * @param  list<array{arity: int|array{0: int, 1: int}, captures: list<string>, self: int|null, map: list<array{0: bool, 1: int, 2: int}>}>  $lambdas  Each lambda record
     * @param  array<string, array{parent: string|null, abstract: bool, fields: array<string, string>, methods: array<string, string>}>  $classes  Each class record
     */
    public function __construct(array $instructions, array $local_names, array $global_names, array $functions = [], array $lambdas = [], array $classes = [])
    {
        $this->classes = $classes;
        $this->instructions = $instructions;
        $this->local_names = $local_names;
        $this->global_names = $global_names;
        $this->functions = $functions;
        $this->lambdas = $lambdas;
    }

    /**
     * The instructions as text, one per line, as `gazlang -c` prints them
     *
     * PUSH writes its value (a scalar, or a list or map built at compile time) as a GazLang literal and PUSH_STR quotes its string; every
     * other argument is a label, name or number written as is.
     */
    public function __toString(): string
    {
        $lines = [];
        foreach ($this->instructions as [$opcode, $args]) {
            $text = match ($opcode) {
                'PUSH' => [match (true) {
                    is_bool($args[0]) => $args[0] ? 'true' : 'false',
                    $args[0] === null => 'null',
                    is_float($args[0]) => Lexer::format_float($args[0]),
                    is_array($args[0]) || $args[0] instanceof MapValue => Values::toString($args[0]),
                    default => (string) $args[0],
                }],
                'PUSH_STR' => [Lexer::quote($args[0])],
                default => $args,
            };
            $lines[] = implode(' ', [$opcode, ...$text]);
        }

        return implode("\n", $lines);
    }
}
