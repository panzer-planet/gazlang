<?php

namespace GazLang\AST;

use GazLang\Lexer\Token;
use GazLang\Runtime\MapValue;
use GazLang\Runtime\Values;

/**
 * Prints a parsed program as text, for `gazlang --ast`: what the self-hosted parser is checked against
 *
 * It reads each node's fields rather than knowing them, so a field added to a node shows up
 * here, and tests/SelfHostedParserTest.php fails until selfhost/parser.gaz has it too.
 *
 * A node is "label: Type line" with its fields indented under it, in the order they are
 * declared. A token prints as --tokens does, a scalar as a GazLang literal, and an array
 * holding only scalars as a list or map literal on one line ([] when empty, since PHP can't
 * tell which it is); any other array has an indented line per element, labelled with its key.
 * A line `@ "file"` comes before a node from a different file than the last.
 */
class Dumper
{
    /**
     * Fields that are not the parser's result: line and file are shown in other ways, the code
     * generator sets existing, and the other two are bookkeeping derived from what is shown
     */
    private const SKIPPED = ['line' => true, 'file' => true, 'existing' => true, 'resolved' => true, 'capture_names' => true];

    /**
     * @var string[] The lines so far
     */
    private array $lines = [];

    /**
     * @var string|null|false The file of the last node that had a line, false before the first
     */
    private string|null|false $file = false;

    /**
     * The whole tree as text, ending in a newline
     *
     * @param  AST  $root  The program
     */
    public static function dump(AST $root): string
    {
        $dumper = new self;
        $dumper->value('', $root, '');

        return implode("\n", $dumper->lines)."\n";
    }

    /**
     * Print one value: a node, a token, an array or a scalar
     *
     * @param  string  $label  What it is to its parent, with the ": " after it, or nothing for the root
     * @param  mixed  $value  The value
     * @param  string  $indent  The indentation of its line
     */
    private function value(string $label, mixed $value, string $indent): void
    {
        if ($value instanceof AST) {
            // A node nothing was read for, like a block, has no line and belongs to no file
            if ($value->line !== null && $value->file !== $this->file) {
                $this->file = $value->file;
                $this->lines[] = '@ '.self::literal($value->file);
            }
            $type = substr(strrchr($value::class, '\\'), 1, -3);
            $this->lines[] = rtrim("{$indent}{$label}{$type} {$value->line}");
            $this->children(array_diff_key(get_object_vars($value), self::SKIPPED), $indent);
        } elseif ($value instanceof Token) {
            $this->lines[] = "{$indent}{$label}{$value}";
        } elseif ($value instanceof MapValue && $value->items === []) {
            // A constant's value. Empty maps and lists print alike, since PHP can't tell its own apart
            $this->lines[] = "{$indent}{$label}[]";
        } elseif (is_array($value) && ! self::isPlain($value)) {
            $this->lines[] = $indent.rtrim($label);
            $this->children($value, $indent);
        } else {
            $this->lines[] = $indent.$label.self::literal(is_array($value) && ! array_is_list($value) ? new MapValue($value) : $value);
        }
    }

    /**
     * A plain value as a GazLang literal: a list prints its elements as literals, so a string is quoted as the lexer read it
     *
     * @param  mixed  $value  A scalar, null, or a list or map of them
     */
    private static function literal(mixed $value): string
    {
        return substr(Values::toString([$value]), 1, -1);
    }

    /**
     * Print a node's fields or an array's elements, each labelled with its name or key
     *
     * @param  array<int|string, mixed>  $children  The values by name or key
     * @param  string  $indent  The indentation of the line they belong to
     */
    private function children(array $children, string $indent): void
    {
        foreach ($children as $key => $child) {
            $this->value("{$key}: ", $child, $indent.'  ');
        }
    }

    /**
     * Whether an array holds only scalars, at any depth, so it can be written as a literal
     *
     * @param  array<int|string, mixed>  $array  The array
     */
    private static function isPlain(array $array): bool
    {
        foreach ($array as $element) {
            if ($element instanceof MapValue) {
                $element = $element->items;
            }
            if (is_object($element) || (is_array($element) && ! self::isPlain($element))) {
                return false;
            }
        }

        return true;
    }
}
