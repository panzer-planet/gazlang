<?php

namespace GazLang\Tests;

/**
 * Checks the GazLang lexer (compiler/lexer.gaz, run by compiler/gazlang.gaz) on its corpus
 *
 * Each tests/lexer_corpus/X.gaz has what `gaz --tokens` must print for it in X.tokens: each
 * token as `LINE TYPE VALUE`, then on a lexer error the error message, printed by
 * error() as "Error: <message> on line N", with exit code 1. The self-hosted lexer is run as
 * `gaz compiler/gazlang.gaz tokens FILE`, and with the file on standard input as
 * `... -- tokens < FILE`, on the C VM (see CVM::driver()). The parser's and code generator's
 * harnesses run the lexer over their corpora too.
 */
class SelfHostedLexerTest extends GazLangTestCase
{
    private const CORPUS = 'tests/lexer_corpus';

    /**
     * The driver's output and exit code by file, run on the C VM for the whole corpus at once
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private static array $results = [];

    /**
     * Every .gaz file in the corpus, keyed by path relative to the project root
     */
    public static function corpus(): array
    {
        $files = [];
        foreach (glob(self::ROOT.'/'.self::CORPUS.'/*.gaz') as $path) {
            $file = self::CORPUS.'/'.basename($path);
            $files[$file] = [$file];
        }

        return $files;
    }

    /**
     * @dataProvider corpus
     */
    public function test_self_hosted_lexer_prints_the_expected_tokens(string $file)
    {
        self::$results = self::$results ?: CVM::driver('tokens', array_keys(self::corpus()));
        $this->assertPortPrints(substr($file, 0, -3).'tokens', self::$results[$file], "Tokens for {$file}");
    }

    /**
     * @dataProvider corpus
     */
    public function test_corpus_files_named_error_are_exactly_the_ones_that_fail(string $file)
    {
        self::$results = self::$results ?: CVM::driver('tokens', array_keys(self::corpus()));

        $this->assertSame(str_starts_with(basename($file), 'error_') ? 1 : 0, self::$results[$file][1]);
    }

    /**
     * Piped source is the same text, so what is tested is the driver reading standard input
     */
    public function test_self_hosted_lexer_prints_the_same_tokens_on_piped_input()
    {
        $files = [self::CORPUS.'/strings.gaz', self::CORPUS.'/error_bad_character.gaz'];
        foreach (CVM::driver('tokens', $files, piped: true) as $file => $result) {
            $this->assertPortPrints(substr($file, 0, -3).'tokens', $result, "Piped tokens for {$file}");
        }
    }

    public function test_every_expected_file_has_its_program()
    {
        $this->assertSame([], self::strayExpectations(self::CORPUS, ['tokens']));
    }
}
