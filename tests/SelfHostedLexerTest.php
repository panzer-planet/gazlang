<?php

namespace GazLang\Tests;

use GazLang\GazLangError;
use GazLang\Lexer\Lexer;
use GazLang\Lexer\Token;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Checks the GazLang lexer (selfhost/lexer.gaz, run by selfhost/gazlang.gaz) against the PHP lexer, which is the spec
 *
 * For every corpus file the PHP lexer's `gazlang --tokens` output is the expected
 * output. The self-hosted lexer is run as `gazlang -f selfhost/gazlang.gaz -- tokens FILE`, and with
 * the file on standard input as `... -- tokens < FILE`,
 * (on the C VM, see CVM::driver()) and must print exactly the same lines and exit with the same code: each token as
 * Token::__toString() formats it, then on a lexer error the error message, printed
 * by error() as "Error: <message> on line N", with exit code 1.
 */
class SelfHostedLexerTest extends GazLangTestCase
{
    /**
     * The driver's output and exit code by file, run on the C VM for the whole corpus at once
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private static array $results = [];

    /**
     * Every .gaz file the lexers are compared on, keyed by path relative to the project root
     */
    public static function corpus(): array
    {
        $files = [];
        // selfhost/ too, so the ported lexer is also checked on its own source
        foreach (['examples', 'lib', 'selfhost', 'tests'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT."/{$dir}")) as $path) {
                $file = substr($path, strlen(self::ROOT) + 1);
                // The parser's and code generator's cases are small programs that add nothing
                // for a lexer, and their harnesses run this lexer over every one of them anyway
                if (str_ends_with($path, '.gaz') && ! str_starts_with($file, 'tests/parser_corpus/') && ! str_starts_with($file, 'tests/codegen_corpus/')) {
                    $files[$file] = [$file];
                }
            }
        }
        ksort($files);

        return $files;
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
            exec(sprintf('cd %s && %s bin/gazlang-php --tokens -f %s 2>&1', escapeshellarg(self::ROOT), escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $exit_code);

            $this->assertSame(self::phpTokens($file), [implode("\n", $output), $exit_code], $file);
            $output = [];
        }
    }

    /**
     * The corpus runs on the C VM only, which is what keeps it fast, so the files that between
     * them reach every part of the lexer go through the interpreter as well
     */
    public function test_self_hosted_lexer_gives_the_same_tokens_on_the_interpreter()
    {
        $files = ['interpolation', 'numbers', 'strings', 'block_comments', 'operators', 'error_interpolated_index', 'error_integer_too_large', 'error_unicode_surrogate'];
        foreach ($files as $name) {
            $file = "tests/lexer_corpus/{$name}.gaz";
            [$output, $exit_code] = $this->runProgram(CVM::DRIVER, ['tokens', $file]);

            $this->assertSame(self::phpTokens($file), [rtrim($output, "\n"), $exit_code], $file);
        }
    }

    /**
     * @dataProvider corpus
     */
    public function test_self_hosted_lexer_matches_the_php_lexer(string $file)
    {
        self::$results = self::$results ?: CVM::driver('tokens', array_keys(self::corpus()));
        $this->assertSameTokens($file, self::$results[$file]);
    }

    /**
     * Piped source is the same text, so what is tested is the driver reading standard input
     */
    public function test_self_hosted_lexer_matches_the_php_lexer_on_piped_input()
    {
        $files = ['tests/lexer_corpus/strings.gaz', 'tests/lexer_corpus/error_bad_character.gaz'];
        foreach (CVM::driver('tokens', $files, piped: true) as $file => $result) {
            $this->assertSameTokens($file, $result);
        }
    }

    /**
     * @param  array{0: string, 1: int}  $result  What the driver printed, and its exit code
     */
    private function assertSameTokens(string $file, array $result): void
    {
        [$expected, $expected_exit_code] = self::phpTokens($file);
        $this->assertSameText($expected, rtrim($result[0], "\n"), "Tokens differ for {$file}");
        $this->assertSame($expected_exit_code, $result[1], "Exit code differs for {$file}");
    }
}
