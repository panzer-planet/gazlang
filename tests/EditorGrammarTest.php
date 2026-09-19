<?php

namespace GazLang\Tests;

use GazLang\Lexer\Lexer;
use GazLang\Runtime\Builtins;
use PHPUnit\Framework\TestCase;

/**
 * editors/gaz.tmLanguage names every builtin and keyword, so a new one fails here until the
 * grammar has it; four builtins and two more went unhighlighted before this test existed
 */
class EditorGrammarTest extends TestCase
{
    private const GRAMMAR = __DIR__.'/../editors/gaz.tmLanguage';

    public function test_it_highlights_exactly_the_builtins()
    {
        $grammar = file_get_contents(self::GRAMMAR);
        $this->assertSame(1, preg_match('#<string>support\.function\.gaz</string>\s*<key>match</key>\s*<string>[^<]*\\\\b\(([a-z_|]+)\)\\\\b</string>#', $grammar, $match), 'the support.function.gaz rule');
        $highlighted = explode('|', $match[1]);
        $builtins = array_keys(Builtins::ARITIES);
        $this->assertSame([], array_values(array_diff($builtins, $highlighted)), 'builtins the grammar is missing');
        $this->assertSame([], array_values(array_diff($highlighted, $builtins)), 'names the grammar highlights that are not builtins');
    }

    public function test_it_highlights_every_keyword()
    {
        preg_match_all('#\\\\b\(([a-z|]+)\)\\\\b#', file_get_contents(self::GRAMMAR), $matches);
        $highlighted = explode('|', implode('|', $matches[1]));
        $this->assertSame([], array_values(array_diff(array_keys(Lexer::KEYWORDS), $highlighted)));
    }
}
