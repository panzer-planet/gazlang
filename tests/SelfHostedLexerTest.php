<?php

namespace GazLang\Tests;

use GazLang\GazLangError;
use GazLang\Lexer\Lexer;
use GazLang\Lexer\Token;
use PHPUnit\Framework\TestCase;

/**
 * Checks the GazLang lexer (selfhost/lexer.gaz) against the PHP lexer, which is the spec
 *
 * For every corpus file the PHP lexer's `gazlang --tokens` output is the expected
 * output. The self-hosted lexer is run as `gazlang -f selfhost/lexer.gaz -- FILE` and
 * must print exactly the same lines and exit with the same code: each token as
 * Token::__toString() formats it, then on a lexer error the error message, printed
 * by error() as "Error: <message> on line N", with exit code 1.
 */
class SelfHostedLexerTest extends TestCase
{
    private const ROOT = __DIR__.'/..';

    private const LEXER = 'selfhost/lexer.gaz';

    /**
     * Every .gaz file the lexers are compared on, keyed by path relative to the project root
     */
    public static function corpus(): array
    {
        $files = [];
        foreach (['examples', 'examples/lib', 'tests/fixtures', 'tests/fixtures/include', 'tests/fixtures/include/lib', 'tests/lexer_corpus'] as $dir) {
            foreach (glob(self::ROOT."/{$dir}/*.gaz") as $path) {
                $files["{$dir}/".basename($path)] = ["{$dir}/".basename($path)];
            }
        }

        return $files;
    }

    /**
     * The corpus, or one placeholder case while the self-hosted lexer doesn't exist, so it skips once
     */
    public static function selfHostedCorpus(): array
    {
        return is_file(self::ROOT.'/'.self::LEXER) ? self::corpus() : [self::LEXER.' not written yet' => [null]];
    }

    /**
     * The expected --tokens output and exit code for a file, from the PHP lexer in-process
     *
     * @return array{0: string, 1: int}
     */
    private static function phpTokens(string $file): array
    {
        $lexer = new Lexer(file_get_contents(self::ROOT."/{$file}"));
        $lines = [];
        try {
            do {
                $token = $lexer->get_next_token();
                $lines[] = (string) $token;
            } while ($token->type !== Token::EOF);
        } catch (GazLangError $e) {
            $lines[] = "Error: {$e->getMessage()}";

            return [implode("\n", $lines), 1];
        }

        return [implode("\n", $lines), 0];
    }

    /**
     * @dataProvider corpus
     */
    public function test_corpus_files_named_error_are_exactly_the_ones_that_fail(string $file)
    {
        [, $exit_code] = self::phpTokens($file);

        $this->assertSame(str_starts_with(basename($file), 'error_') ? 1 : 0, $exit_code);
    }

    public function test_in_process_expectation_matches_the_cli()
    {
        foreach (['tests/lexer_corpus/strings.gaz', 'tests/lexer_corpus/error_bad_character.gaz'] as $file) {
            exec(sprintf('cd %s && %s bin/gazlang --tokens -f %s', escapeshellarg(self::ROOT), escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $exit_code);

            $this->assertSame(self::phpTokens($file), [implode("\n", $output), $exit_code], $file);
            $output = [];
        }
    }

    /**
     * @dataProvider selfHostedCorpus
     */
    public function test_self_hosted_lexer_matches_the_php_lexer(?string $file)
    {
        if ($file === null) {
            $this->markTestSkipped(self::LEXER.' has not been written yet');
        }

        exec(sprintf(
            'cd %s && %s bin/gazlang -f %s -- %s 2>&1',
            escapeshellarg(self::ROOT),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::LEXER),
            escapeshellarg($file)
        ), $output, $exit_code);

        [$expected, $expected_exit_code] = self::phpTokens($file);
        $this->assertSame($expected, implode("\n", $output), "Tokens differ for {$file}");
        $this->assertSame($expected_exit_code, $exit_code, "Exit code differs for {$file}");
    }
}
