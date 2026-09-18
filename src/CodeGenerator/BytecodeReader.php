<?php

namespace GazLang\CodeGenerator;

use GazLang\GazLangError;
use GazLang\Lexer\Lexer;
use GazLang\Lexer\Token;
use GazLang\Runtime\Builtins;
use GazLang\Runtime\MapValue;

/**
 * Reads a bytecode file into a Program (see docs/bytecode.md)
 *
 * Nothing is trusted: every instruction, argument, label and name is checked, and each
 * block's stack is walked to prove it balances, so a file that loads is one the VM can
 * run. A C VM reads the same file with the same checks, and can take each block's greatest
 * stack depth from the same walk to size its frames.
 */
final class BytecodeReader
{
    /**
     * Instructions after which the next one is only reached through a label
     */
    private const TERMINATORS = ['JMP' => true, 'RET' => true, 'RETHROW' => true, 'HALT' => true];

    /**
     * @var list<string> The lines of the file
     */
    private $lines;

    /**
     * @var string|null The file's own path, for locating load errors
     */
    private $path;

    /**
     * @var string The directory source paths are resolved against
     */
    private $base;

    /**
     * @var int The line being read, counting from 1
     */
    private $line = 0;

    /**
     * Constructor
     *
     * @param  string  $text  The file
     * @param  string|null  $path  The file's own path, as the user named it
     */
    public function __construct(string $text, ?string $path = null)
    {
        $this->lines = array_map(fn (string $line) => rtrim($line, "\r"), explode("\n", $text));
        $this->path = $path;
        $this->base = Program::absolute($path === null ? '.' : dirname($path));
    }

    /**
     * Read the file
     *
     * @throws GazLangError If it is not bytecode this version can run
     */
    public function read(): Program
    {
        $header = $this->words($this->next() ?? '');
        if (count($header) !== 3 || "{$header[0]} {$header[1]}" !== Program::MAGIC) {
            $this->fail('Not a bytecode file');
        }
        if ($header[2] !== (string) Program::VERSION) {
            $this->fail("Bytecode version {$header[2]}, but this is GazLang bytecode ".Program::VERSION);
        }

        $globals = $this->words($this->next() ?? '');
        if (array_shift($globals) !== 'globals') {
            $this->fail("Expected 'globals'");
        }

        $blocks = [];
        while (($line = $this->next()) !== null) {
            $blocks[] = $this->block($line);
        }
        if ($blocks === [] || $blocks[0]['kind'] !== 'top') {
            $this->fail('Expected a top block');
        }

        $program = new Program($blocks, $globals);
        foreach ($program->classes as $name => $class) {
            $this->records($name, $class, $program);
        }
        foreach ($blocks as $block) {
            $this->check($block, $program);
        }

        return $program;
    }

    /**
     * Check a class's record: its parent, the class each field is declared by, and the block each method runs
     *
     * @param  string  $name  The class's name
     * @param  array{parent: string|null, abstract: bool, fields: array<string, string>, methods: array<string, string>}  $class  Its record
     * @param  Program  $program  The program, for what it can name
     */
    private function records(string $name, array $class, Program $program): void
    {
        $fail = fn (string $message) => $this->fail("{$message} in class {$name}", false);
        if ($class['parent'] !== null && ! isset($program->classes[$class['parent']])) {
            $fail("Undefined class '{$class['parent']}'");
        }
        foreach ($class['fields'] as $field => $declarer) {
            if (! isset($program->classes[$declarer])) {
                $fail("Field {$field} is declared by undefined class '{$declarer}'");
            }
        }
        foreach ($class['methods'] as $method => $definer) {
            if (! isset($program->classes[$definer])) {
                $fail("Method {$method} runs undefined class '{$definer}'");
            } elseif (! isset($program->functions["{$definer}.{$method}"])) {
                $fail("Method {$method} has no block {$definer}.{$method}");
            }
        }
    }

    /**
     * Read one block, from its first header line
     *
     * @param  string  $line  The header line
     * @return array<string, mixed> The block
     */
    private function block(string $line): array
    {
        $words = $this->words($line);
        $block = match ($words[0]) {
            'top' => ['kind' => 'top'],
            'fn' => ['kind' => 'fn', 'name' => $words[1] ?? '', 'arity' => $this->arity(array_slice($words, 2))],
            'class', 'abstract' => $this->classBlock($words),
            'lambda' => ['kind' => 'lambda', 'index' => $this->count($words[1] ?? ''), 'arity' => $this->arity(array_slice($words, 2))],
            default => $this->fail("Unknown block '{$words[0]}'"),
        };
        if ($block['kind'] === 'top' && count($words) !== 1) {
            $this->fail("Expected 'top' on its own");
        }

        // The record lines a class or lambda block carries, then its locals
        $block += ['fields' => [], 'methods' => [], 'captures' => [], 'map' => [], 'self' => null];
        while (true) {
            $words = $this->words($this->next() ?? $this->fail("Expected 'locals'"));
            if ($words[0] === 'field' && $block['kind'] === 'class') {
                $block['fields'][$this->word($words, 1)] = $this->word($words, 2);
            } elseif ($words[0] === 'method' && $block['kind'] === 'class') {
                $block['methods'][$this->word($words, 1)] = $this->word($words, 2);
            } elseif ($words[0] === 'capture' && $block['kind'] === 'lambda') {
                $block['map'][] = $this->capture($words, $block);
            } elseif ($words[0] === 'self' && $block['kind'] === 'lambda') {
                $block['self'] = $this->captureIndex($this->word($words, 1), $block);
            } elseif (array_shift($words) === 'locals') {
                $block['locals'] = $words;
                break;
            } else {
                $this->fail("Expected 'locals'");
            }
        }

        $block['code'] = $this->code();

        return $block;
    }

    /**
     * Read a class block's header line
     *
     * @param  list<string>  $words  The line's words
     * @return array<string, mixed> The block, without its record lines
     */
    private function classBlock(array $words): array
    {
        $abstract = $words[0] === 'abstract';
        if ($abstract && ($words[1] ?? '') !== 'class') {
            $this->fail("Expected 'abstract class'");
        }
        $words = array_slice($words, $abstract ? 2 : 1);
        if ($words !== [] && count($words) !== 1 && ! (count($words) === 3 && $words[1] === 'extends')) {
            $this->fail("Expected 'class Name' or 'class Name extends Parent'");
        }

        return ['kind' => 'class', 'name' => $this->word($words, 0), 'abstract' => $abstract, 'parent' => $words[2] ?? null];
    }

    /**
     * Read a lambda block's capture line into its capture map entry
     *
     * @param  list<string>  $words  The line's words
     * @param  array<string, mixed>  $block  The block so far, which gains the captured name
     * @return array{0: bool, 1: int, 2: int} Whether the source is the enclosing closure's variable, its slot or index there, the index here
     */
    private function capture(array $words, array &$block): array
    {
        $where = $this->word($words, 2);
        if ($where !== 'local' && $where !== 'captured') {
            $this->fail("Expected 'local' or 'captured'");
        }
        $block['captures'][] = $this->word($words, 1);

        return [$where === 'captured', $this->count($this->word($words, 3)), count($block['captures']) - 1];
    }

    /**
     * The index of a lambda's captured variable, by name
     *
     * @param  string  $name  The variable
     * @param  array<string, mixed>  $block  The block so far
     */
    private function captureIndex(string $name, array $block): int
    {
        $index = array_search($name, $block['captures'], true);
        if ($index === false) {
            $this->fail("{$name} is not captured");
        }

        return $index;
    }

    /**
     * Read a block's instructions, up to the next block or the end of the file
     *
     * @return list<array{0: string, 1: array, 2: string|null, 3: int|null}> The instructions with their locations
     */
    private function code(): array
    {
        $code = [];
        [$file, $line] = [null, null];
        while (($text = $this->next()) !== null) {
            $words = $this->words($text);
            if ($words[0] === '@') {
                [$file, $line] = $this->location($words);

                continue;
            }
            if (! isset(Program::INSTRUCTIONS[$words[0]])) {
                // A lowercase word starts the next block; anything else is meant to be an instruction
                if (preg_match('/^[a-z]/', $words[0])) {
                    $this->back();

                    return $code;
                }
                $this->fail("Unknown instruction '{$words[0]}'");
            }

            $code[] = [$words[0], $this->arguments($words, $text), $file, $line];
        }

        return $code;
    }

    /**
     * Read an instruction's arguments, converting each one to what the VM holds
     *
     * @param  list<string>  $words  The line's words
     * @param  string  $text  The whole line, for a value argument, which is the rest of it
     * @return list<mixed> The arguments
     */
    private function arguments(array $words, string $text): array
    {
        [$kinds] = Program::INSTRUCTIONS[$words[0]];
        $arguments = [];
        foreach ($kinds as $i => $kind) {
            $word = $this->word($words, $i + 1);
            $arguments[] = match ($kind) {
                'slot', 'count', 'lambda' => $this->count($word),
                'value' => $this->value(substr($text, strpos($text, ' ') + 1)),
                'path' => $this->path($word, false),
                'element_path' => $this->path($word, true),
                default => $word,
            };
        }
        if (count($words) !== count($kinds) + 1 && ! in_array('value', $kinds, true)) {
            $this->fail("{$words[0]} takes ".count($kinds).' argument'.(count($kinds) === 1 ? '' : 's'));
        }

        return $arguments;
    }

    /**
     * Read a write path, like [k].total[]
     *
     * @param  string  $path  The path
     * @param  bool  $element  Whether it must end at an element of a list or map, as a removal does
     */
    private function path(string $path, bool $element): string
    {
        if (! preg_match($element ? '/^(\[k\]|\.\w+)*\[k\]$/' : '/^(\[k\]|\[\]|\.\w+)+$/', $path)) {
            $this->fail("Bad path '{$path}'");
        }

        return $path;
    }

    /**
     * Read an @ line's file and line
     *
     * @param  list<string>  $words  The line's words
     * @return array{0: string|null, 1: int} The file as the parser would show it, and the line
     */
    private function location(array $words): array
    {
        if (count($words) === 2) {
            return [null, $this->count($words[1])];
        }
        if (count($words) !== 3 || ! str_starts_with($words[1], '"')) {
            $this->fail('Expected @ "file" line or @ line');
        }
        $file = $this->value($words[1]);
        if (! is_string($file)) {
            $this->fail('Expected @ "file" line');
        }

        return [str_starts_with($file, '<') ? $file : $this->display($file), $this->count($words[2])];
    }

    /**
     * A source path as the parser shows it: relative to the working directory when it is under it
     *
     * @param  string  $file  The path, relative to the bytecode file's directory
     */
    private function display(string $file): string
    {
        $path = Program::absolute(str_starts_with($file, '/') ? $file : "{$this->base}/{$file}");
        $cwd = getcwd().'/';

        return str_starts_with($path, $cwd) ? substr($path, strlen($cwd)) : $path;
    }

    /**
     * Read a value written as a GazLang literal
     *
     * @param  string  $text  The literal
     * @return mixed The value
     */
    private function value(string $text)
    {
        $lexer = new Lexer($text);
        try {
            $token = $lexer->get_next_token();
            $value = $this->literal($lexer, $token);
            if ($lexer->get_next_token()->type !== Token::EOF) {
                $this->fail("Bad value '{$text}'");
            }
        } catch (GazLangError $error) {
            $this->fail("Bad value '{$text}': {$error->reason}");
        }

        return $value;
    }

    /**
     * Read one literal from the lexer: a scalar, a list or a map
     *
     * @param  Lexer  $lexer  The lexer, positioned after the token
     * @param  Token  $token  The literal's first token
     * @return mixed The value
     */
    private function literal(Lexer $lexer, Token $token)
    {
        if ($token->type === Token::MINUS) {
            try {
                $number = $lexer->get_next_token();
            } catch (GazLangError $error) {
                // The smallest int is written like any other, and its digits alone are one too
                // many for an int: a loader has to read the sign and the digits as one number
                if ($error->reason === 'Integer literal too large: 9223372036854775808') {
                    return PHP_INT_MIN;
                }

                throw $error;
            }
            $value = $this->literal($lexer, $number);
            if (! is_int($value) && ! is_float($value)) {
                $this->fail('Bad value: - takes a number');
            }

            return -$value;
        }
        if ($token->type === Token::LEFT_BRACKET || $token->type === Token::LEFT_BRACE) {
            return $this->items($lexer, $token->type === Token::LEFT_BRACE);
        }

        return match ($token->type) {
            Token::INTEGER, Token::FLOAT, Token::STRING => $token->value,
            Token::TRUE => true,
            Token::FALSE => false,
            Token::NULL => null,
            default => $this->fail("Bad value: unexpected {$token->type}"),
        };
    }

    /**
     * Read the items of a list or map literal, up to its closing bracket
     *
     * @param  Lexer  $lexer  The lexer, positioned after the opening bracket
     * @param  bool  $map  Whether it is a map, whose items are key => value
     * @return array|MapValue The list or map
     */
    private function items(Lexer $lexer, bool $map)
    {
        $end = $map ? Token::RIGHT_BRACE : Token::RIGHT_BRACKET;
        $items = [];
        for ($token = $lexer->get_next_token(); $token->type !== $end; $token = $lexer->get_next_token()) {
            $value = $this->literal($lexer, $token);
            if ($map) {
                if ($lexer->get_next_token()->type !== Token::DOUBLE_ARROW) {
                    $this->fail("Bad value: expected '=>'");
                }
                if (! is_int($value) && ! is_string($value)) {
                    $this->fail('Bad value: a key must be an int or a string');
                }
                $items[MapValue::key($value)] = $this->literal($lexer, $lexer->get_next_token());
            } else {
                $items[] = $value;
            }

            $token = $lexer->get_next_token();
            if ($token->type === $end) {
                break;
            }
            if ($token->type !== Token::COMMA) {
                $this->fail("Bad value: expected ',' or the end of the literal");
            }
        }

        return $map ? new MapValue($items) : $items;
    }

    /**
     * Check a block: every name it uses exists, every label is defined, and its stack balances
     *
     * The stack is walked from the top of the block, following jumps: an instruction's height
     * must be the same however it is reached, nothing may be popped off an empty stack, and a
     * try's handler starts one deeper, holding the error. The greatest depth reached is what a
     * VM needs to size the block's stack.
     *
     * @param  array<string, mixed>  $block  The block
     * @param  Program  $program  The program, for the names it can use
     */
    private function check(array $block, Program $program): void
    {
        $where = Program::key($block);
        $where = $where === '' ? 'the top level' : "'{$where}'";
        $fail = fn (string $message) => $this->fail("{$message} in {$where}", false);

        $labels = [];
        foreach ($block['code'] as $position => [$opcode, $args]) {
            if ($opcode === 'LABEL') {
                $labels[$args[0]] = $position;
            }
        }

        // Where each instruction is reached with the stack at that height, and where to carry on from
        $heights = [];
        $worklist = [[0, 0]];
        while ($worklist !== []) {
            [$position, $height] = array_pop($worklist);
            while ($position < count($block['code'])) {
                if (isset($heights[$position])) {
                    if ($heights[$position] !== $height) {
                        $what = implode(' ', [$block['code'][$position][0], ...$block['code'][$position][1]]);
                        $fail("The stack is {$height} deep at {$what} (instruction {$position}), but {$heights[$position]} on another path");
                    }
                    break;
                }
                $heights[$position] = $height;
                [$opcode, $args] = $block['code'][$position];
                [, [$pops, $pushes]] = Program::INSTRUCTIONS[$opcode];

                foreach (Program::INSTRUCTIONS[$opcode][0] as $i => $kind) {
                    $this->name($kind, $args[$i], $block, $program, $labels, $fail);
                }

                if (! is_int($pops)) {
                    // The keys a path takes from the stack, or an argument count and what is called with it
                    $pops = match ($pops) {
                        'path' => substr_count($args[0], '[k]') + 1,
                        'keys' => substr_count($args[0], '[k]'),
                        default => $args[array_search('count', Program::INSTRUCTIONS[$opcode][0], true)] + (int) substr($pops, 6),
                    };
                }
                if ($height < $pops) {
                    $fail("{$opcode} needs {$pops} value".($pops === 1 ? '' : 's')." but the stack is {$height} deep at instruction {$position}");
                }
                $height += $pushes - $pops;

                // A jump reaches its label with the stack as it is here; JNN keeps the value it tested
                if (isset($args[0]) && in_array($opcode, ['JMP', 'JZ', 'JNN', 'TRY'], true)) {
                    $worklist[] = [$labels[$args[0]], $opcode === 'JNN' ? $height + 1 : ($opcode === 'TRY' ? $height + 1 : $height)];
                } elseif ($opcode === 'CATCH_MATCH') {
                    $worklist[] = [$labels[$args[1]], $height];
                }
                if (isset(self::TERMINATORS[$opcode])) {
                    break;
                }
                $position++;
            }

            // Only the top level may end by running out of instructions, which ends the program
            if ($position >= count($block['code']) && $block['kind'] !== 'top') {
                $fail('The code runs off the end of the block, which must end in RET');
            }
        }
    }

    /**
     * Check one argument that names something: a label, a function, a class, a lambda or a slot
     *
     * A member name is not checked: which members an object has is only known when it runs.
     *
     * @param  string  $kind  The argument kind
     * @param  mixed  $argument  The argument
     * @param  array<string, mixed>  $block  The block it is in
     * @param  Program  $program  The program
     * @param  array<string, int>  $labels  The block's labels
     * @param  callable  $fail  How to report a bad argument
     */
    private function name(string $kind, $argument, array $block, Program $program, array $labels, callable $fail): void
    {
        match ($kind) {
            'label' => isset($labels[$argument]) || $fail("Undefined label '{$argument}'"),
            'function' => isset($program->functions[$argument]) || $fail("Undefined function '{$argument}'"),
            'callable' => isset($program->functions[$argument]) || isset(Builtins::ARITIES[$argument]) || $fail("Undefined function '{$argument}'"),
            'builtin' => isset(Builtins::ARITIES[$argument]) || $fail("Undefined builtin '{$argument}'"),
            'class' => isset($program->classes[$argument]) || $fail("Undefined class '{$argument}'"),
            'lambda' => isset($program->lambdas[$argument]) || $fail("Undefined lambda {$argument}"),
            'slot' => $argument < count($block['locals']) || $fail("Slot {$argument} is not one of the block's ".count($block['locals']).' locals'),
            'global' => $argument < count($program->global_names) || $fail("Global slot {$argument} is not one of the program's ".count($program->global_names).' globals'),
            'capture' => $argument < count($block['captures']) || $fail("Capture {$argument} is not one of the block's ".count($block['captures']).' captured variables'),
            default => null,
        };
    }

    /**
     * The next line with anything on it, or null at the end of the file
     */
    private function next(): ?string
    {
        while ($this->line < count($this->lines)) {
            $line = trim($this->lines[$this->line++]);
            if ($line !== '') {
                return $line;
            }
        }

        return null;
    }

    /**
     * Go back to the line just read, which belongs to the next block
     */
    private function back(): void
    {
        $this->line--;
    }

    /**
     * A line's words, splitting on runs of whitespace
     *
     * @return non-empty-list<string> The words
     */
    private function words(string $line): array
    {
        $words = preg_split('/\s+/', $line);

        return $words === false || $words === [] ? [''] : $words;
    }

    /**
     * One word of a line, which must be there
     *
     * @param  list<string>  $words  The line's words
     * @param  int  $index  Which word
     */
    private function word(array $words, int $index): string
    {
        return $words[$index] ?? $this->fail('Unexpected end of line');
    }

    /**
     * A word as a count: a whole number, written in decimal
     *
     * @param  string  $word  The word
     */
    private function count(string $word): int
    {
        if (! preg_match('/^\d+$/', $word)) {
            $this->fail("Expected a number but found '{$word}'");
        }

        return (int) $word;
    }

    /**
     * A block header's arity, written as fewest then most
     *
     * @param  list<string>  $words  The words after the block's name
     * @return int|array{0: int, 1: int} The arity, a count when they are the same
     */
    private function arity(array $words)
    {
        if (count($words) !== 2) {
            $this->fail('Expected an arity, written as fewest then most');
        }
        [$fewest, $most] = [$this->count($words[0]), $this->count($words[1])];
        if ($fewest > $most) {
            $this->fail("An arity of {$fewest} to {$most} takes nothing");
        }

        return $fewest === $most ? $fewest : [$fewest, $most];
    }

    /**
     * Report a bad file
     *
     * @param  string  $message  What is wrong
     * @param  bool  $locate  Whether to name the line it is on, which a check of a whole block can't
     *
     * @throws GazLangError
     */
    private function fail(string $message, bool $locate = true): never
    {
        throw new GazLangError($message, $this->path, $locate ? $this->line : null, $locate);
    }
}
