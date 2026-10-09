<?php

namespace GazLang\Tests;

/**
 * editors/gaz/gaz.tmLanguage names every builtin and keyword, and matches every operator as one,
 * so a new one fails here until the grammar has it; four builtins and two more went unhighlighted before this test existed. The same
 * holds for editors/gzb/gzb.tmLanguage and the bytecode's instructions.
 */
class EditorGrammarTest extends GazLangTestCase
{
    private const GRAMMAR = __DIR__.'/../editors/gaz/gaz.tmLanguage';

    public function test_it_highlights_exactly_the_builtins()
    {
        $grammar = file_get_contents(self::GRAMMAR);
        $this->assertSame(1, preg_match('#<string>support\.function\.gaz</string>\s*<key>match</key>\s*<string>[^<]*\\\\b\(([a-z0-9_|]+)\)\\\\b</string>#', $grammar, $match), 'the support.function.gaz rule');
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
        // The keywords are the lexer's table, KEYWORDS in lib/syntax.gaz
        $lexer = (string) file_get_contents(self::ROOT.'/lib/syntax.gaz');
        $this->assertSame(1, preg_match('/const KEYWORDS = \{(.*?)\};/s', $lexer, $table));
        preg_match_all('/"([a-z]+)" =>/', $table[1], $keywords);
        $this->assertGreaterThan(20, count($keywords[1]));
        $this->assertSame([], array_values(array_diff($keywords[1], $highlighted)));
    }

    public function test_it_marks_include_as_removed_not_as_a_keyword()
    {
        $grammar = (string) file_get_contents(self::GRAMMAR);
        $this->assertSame(1, preg_match('#<string>invalid\\.deprecated\\.removed\\.gaz</string>\\s*<key>match</key>\\s*<string>\\\\b\\(include\\)\\\\b</string>#', $grammar), 'a rule marking include invalid');
        // And no keyword rule colours it as one
        preg_match('#<string>keyword\\.control\\.gaz</string>\\s*<key>match</key>\\s*<string>([^<]+)</string>#', $grammar, $keywords);
        $this->assertStringNotContainsString('include', $keywords[1]);
        $this->assertStringContainsString('import', $keywords[1]);
    }

    public function test_it_highlights_every_operator_as_one()
    {
        $grammar = (string) file_get_contents(self::GRAMMAR);
        $this->assertSame(1, preg_match('#<string>keyword\.operator\.gaz</string>\s*<key>match</key>\s*<string>([^<]+)</string>#', $grammar, $rule), 'the keyword.operator.gaz rule');
        $pattern = html_entity_decode($rule[1], ENT_XML1);
        // The operators are the lexer's table, OPERATORS in lib/syntax.gaz
        $lexer = (string) file_get_contents(self::ROOT.'/lib/syntax.gaz');
        $this->assertSame(1, preg_match('/const OPERATORS = \{(.*?)\};/s', $lexer, $table));
        preg_match_all('/"([^"]+)" =>/', $table[1], $operators);
        $this->assertGreaterThan(40, count($operators[1]));
        // :: is coloured with the names it joins, by the rule for json::decode
        foreach (array_diff($operators[1], ['::']) as $operator) {
            // The first alternative that matches wins, as in an editor, so the match is the whole operator
            preg_match('#^(?:'.$pattern.')#', $operator, $match);
            $this->assertSame($operator, $match[0] ?? null, "{$operator} as one operator");
        }
    }

    /**
     * A docblock is scoped as documentation, and the empty comment written with two stars and a
     * plain block comment are not: the first of the comment rules that matches at the start wins,
     * as in an editor
     */
    public function test_it_scopes_a_docblock_as_documentation()
    {
        $plist = simplexml_load_string((string) file_get_contents(self::GRAMMAR));
        $this->assertNotFalse($plist);
        $repository = self::plistValue($plist->dict)['repository'];
        $scopeOf = function (string $text) use ($repository): ?string {
            foreach ($repository['comments']['patterns'] as $pattern) {
                $rule = isset($pattern['include']) ? $repository[substr($pattern['include'], 1)] : $pattern;
                if (preg_match('#^(?:'.($rule['begin'] ?? $rule['match']).')#', $text)) {
                    return $rule['name'];
                }
            }

            return null;
        };

        $this->assertSame('comment.block.documentation.gaz', $scopeOf("/**\n * Adds\n */"));
        $this->assertSame('comment.block.documentation.gaz', $scopeOf('/** Adds */'));
        $this->assertSame('comment.block.gaz', $scopeOf('/**/'));
        $this->assertSame('comment.block.gaz', $scopeOf('/* plain */'));
        $this->assertSame('comment.line.double-slash.gaz', $scopeOf('// line'));
    }

    /**
     * A property list's value as PHP's: a dict a map, an array a list, a string itself
     */
    private static function plistValue(\SimpleXMLElement $node): mixed
    {
        if ($node->getName() === 'array') {
            $values = [];
            foreach ($node->children() as $child) {
                $values[] = self::plistValue($child);
            }

            return $values;
        }
        if ($node->getName() !== 'dict') {
            return (string) $node;
        }
        $map = [];
        $key = null;
        foreach ($node->children() as $child) {
            if ($child->getName() === 'key') {
                $key = (string) $child;
            } else {
                $map[$key] = self::plistValue($child);
            }
        }

        return $map;
    }

    public function test_the_template_grammar_names_exactly_the_directives()
    {
        $grammar = (string) file_get_contents(self::ROOT.'/editors/gazml/gazml.tmLanguage');
        $this->assertNotFalse(simplexml_load_string($grammar), 'a well formed property list');
        $this->assertStringContainsString('<string>text.html.gazml</string>', $grammar);
        $this->assertStringContainsString('<string>gazml</string>', $grammar);
        preg_match_all('/\(@\(\?:([a-z|]+)\)\)/', $grammar, $rules);
        $named = explode('|', implode('|', $rules[1]));
        // The directives are the match in directive() in compiler/template.gaz, and @template
        $source = (string) file_get_contents(self::ROOT.'/compiler/template.gaz');
        $this->assertSame(1, preg_match('/return match \(\$word\) \{(.*?)default =>/s', $source, $arms));
        preg_match_all('/"([a-z]+)" =>/', $arms[1], $words);
        $this->assertGreaterThan(5, count($words[1]));
        $this->assertSame([], array_values(array_diff($words[1], $named)), 'directives the grammar is missing');
        $this->assertSame([], array_values(array_diff($named, $words[1])), 'names the grammar highlights that are not directives');
        $this->assertStringContainsString('(@template)', $grammar);
        // A template may import before its @template line, as any file may before its declarations
        $this->assertStringContainsString('<string>^\\s*(?=import\\b)</string>', $grammar);
    }
}
