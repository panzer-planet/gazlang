<?php

namespace GazLang\Tests;

use GazLang\CodeGenerator\Program;
use GazLang\GazLangError;
use GazLang\Lexer\Lexer;
use GazLang\Lexer\Token;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Checks the GazLang lexer (selfhost/lexer.gaz, run by selfhost/tokens.gaz) against the PHP lexer, which is the spec
 *
 * For every corpus file the PHP lexer's `gazlang --tokens` output is the expected
 * output. The self-hosted lexer is run as `gazlang -f selfhost/tokens.gaz -- FILE`
 * (in-process on the VM, see runCompiled()) and must print exactly the same lines and exit with the same code: each token as
 * Token::__toString() formats it, then on a lexer error the error message, printed
 * by error() as "Error: <message> on line N", with exit code 1.
 */
class SelfHostedLexerTest extends GazLangTestCase
{
    private const LEXER = 'selfhost/tokens.gaz';

    /**
     * The driver, compiled once: parsing and compiling the lexer again for every corpus file
     * cost more than lexing the files did
     */
    private static ?Program $program = null;

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
                // The parser's cases are small programs that add nothing for a lexer, and
                // SelfHostedParserTest runs this lexer over every one of them anyway
                if (str_ends_with($path, '.gaz') && ! str_starts_with($file, 'tests/parser_corpus/')) {
                    $files[$file] = [$file];
                }
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * The lexer's own cases, which always run
     */
    public static function lexerCorpus(): array
    {
        return array_filter(self::corpus(), fn (string $file) => str_starts_with($file, 'tests/lexer_corpus/'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Every other .gaz file in the repository, which only runs with --group whole-repository
     */
    public static function repositoryCorpus(): array
    {
        return array_diff_key(self::corpus(), self::lexerCorpus());
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
            exec(sprintf('cd %s && %s bin/gazlang --tokens -f %s 2>&1', escapeshellarg(self::ROOT), escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $exit_code);

            $this->assertSame(self::phpTokens($file), [implode("\n", $output), $exit_code], $file);
            $output = [];
        }
    }

    /**
     * The corpus runs on the VM only, which is what keeps it fast, so the files that between
     * them reach every part of the lexer go through the interpreter as well
     */
    public function test_self_hosted_lexer_gives_the_same_tokens_on_the_interpreter()
    {
        $files = ['interpolation', 'numbers', 'strings', 'block_comments', 'operators', 'error_interpolated_index', 'error_integer_too_large', 'error_unicode_surrogate'];
        foreach ($files as $name) {
            $file = "tests/lexer_corpus/{$name}.gaz";
            [$output, $exit_code] = $this->runProgram(self::LEXER, [$file]);

            $this->assertSame(self::phpTokens($file), [rtrim($output, "\n"), $exit_code], $file);
        }
    }

    /**
     * @dataProvider lexerCorpus
     */
    public function test_self_hosted_lexer_matches_the_php_lexer(string $file)
    {
        $this->assertSameTokens($file);
    }

    /**
     * @dataProvider repositoryCorpus
     *
     * @group whole-repository
     */
    public function test_self_hosted_lexer_matches_the_php_lexer_on_the_rest_of_the_repository(string $file)
    {
        $this->assertSameTokens($file);
    }

    private function assertSameTokens(string $file): void
    {
        self::$program ??= self::compileProgram(self::LEXER);
        [$output, $exit_code] = $this->runCompiled(self::$program, [$file]);

        [$expected, $expected_exit_code] = self::phpTokens($file);
        $this->assertSame($expected, rtrim($output, "\n"), "Tokens differ for {$file}");
        $this->assertSame($expected_exit_code, $exit_code, "Exit code differs for {$file}");
    }
}
