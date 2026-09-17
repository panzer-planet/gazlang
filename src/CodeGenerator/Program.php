<?php

namespace GazLang\CodeGenerator;

use GazLang\AST\LambdaAST;
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
     * @var array<string, list<string>> The variable name in each local slot, for the top level ('') and each function (by name)
     */
    public $local_names;

    /**
     * @var list<string> The variable name in each global slot
     */
    public $global_names;

    /**
     * @var array<string, int|array{0: int, 1: int}> Each user function's arity, for calls on function values
     */
    public $functions;

    /**
     * @var list<array{0: LambdaAST, 1: list<array{0: bool, 1: int, 2: int}>}> Each lambda (its body is at LABEL LAMBDA_n) with its
     *                                                                         capture map: [from the enclosing closure rather than the frame,
     *                                                                         slot or index there, index in the new closure]
     */
    public $lambdas;

    /**
     * Constructor
     *
     * @param  list<array{0: string, 1: array, 2: string|null, 3: int|null}>  $instructions  The instructions
     * @param  array<string, list<string>>  $local_names  Local slot names by frame
     * @param  list<string>  $global_names  Global slot names
     * @param  array<string, int|array{0: int, 1: int}>  $functions  Each user function's arity
     * @param  list<array{0: LambdaAST, 1: list<array{0: bool, 1: int, 2: int}>}>  $lambdas  Each lambda with its capture map
     */
    public function __construct(array $instructions, array $local_names, array $global_names, array $functions = [], array $lambdas = [])
    {
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
