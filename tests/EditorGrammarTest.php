<?php

namespace GazLang\Tests;

/**
 * editors/gaz/gaz.tmLanguage names every builtin and keyword, so a new one fails here until the
 * grammar has it; four builtins and two more went unhighlighted before this test existed. The same
 * holds for editors/gzb/gzb.tmLanguage and the bytecode's instructions.
 */
class EditorGrammarTest extends GazLangTestCase
{
    private const GRAMMAR = __DIR__.'/../editors/gaz/gaz.tmLanguage';

    public function test_it_highlights_exactly_the_builtins()
    {
        $grammar = file_get_contents(self::GRAMMAR);
        $this->assertSame(1, preg_match('#<string>support\.function\.gaz</string>\s*<key>match</key>\s*<string>[^<]*\\\\b\(([a-z_|]+)\)\\\\b</string>#', $grammar, $match), 'the support.function.gaz rule');
        $highlighted = explode('|', $match[1]);
        $builtins = explode(' ', trim($this->executeCode('echo join(keys(builtins()), " ");')));
        $this->assertSame([], array_values(array_diff($builtins, $highlighted)), 'builtins the grammar is missing');
        $this->assertSame([], array_values(array_diff($highlighted, $builtins)), 'names the grammar highlights that are not builtins');
    }

    public function test_the_bytecode_grammar_names_exactly_the_instructions()
    {
        // Its catch-all rule lists every instruction, and marks any other uppercase word invalid
        $grammar = (string) file_get_contents(self::ROOT.'/editors/gzb/gzb.tmLanguage');
        $this->assertSame(1, preg_match('#<string>\^\\\\s\*\(([A-Z_|]{200,})\)\\\\b</string>#', $grammar, $match), 'the rule naming every instruction');
        preg_match_all('/\[OP_\w+\] = \{"([A-Z_]+)"/', (string) file_get_contents(self::ROOT.'/vm/load.c'), $info);
        $this->assertGreaterThan(80, count($info[1]));
        $this->assertSame($info[1], explode('|', $match[1]));
    }

    public function test_it_highlights_every_keyword()
    {
        preg_match_all('#\\\\b\(([a-z|]+)\)\\\\b#', file_get_contents(self::GRAMMAR), $matches);
        $highlighted = explode('|', implode('|', $matches[1]));
        // The keywords are the lexer's table, KEYWORDS in compiler/lexer.gaz
        $lexer = (string) file_get_contents(self::ROOT.'/compiler/lexer.gaz');
        $this->assertSame(1, preg_match('/const KEYWORDS = \{(.*?)\};/s', $lexer, $table));
        preg_match_all('/"([a-z]+)" =>/', $table[1], $keywords);
        $this->assertGreaterThan(20, count($keywords[1]));
        $this->assertSame([], array_values(array_diff($keywords[1], $highlighted)));
    }
}
