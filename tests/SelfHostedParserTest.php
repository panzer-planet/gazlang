<?php

namespace GazLang\Tests;

use GazLang\AST\Dumper;
use GazLang\CodeGenerator\Program;
use GazLang\GazLangError;
use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;
use GazLang\Runtime\Builtins;
use GazLang\Runtime\MapValue;
use GazLang\Runtime\Values;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Checks the GazLang parser (selfhost/parser.gaz, run by selfhost/ast.gaz) against the PHP parser, which is the spec
 *
 * For every corpus file the PHP parser's `gazlang --ast` output is the expected output: the
 * tree as AST\Dumper prints it, or for a file that doesn't parse "Error: <message> at FILE:N"
 * and exit code 1. The self-hosted parser is run as `gazlang -f selfhost/ast.gaz -- FILE`
 * (in-process on the VM, see runCompiled()) and must print exactly the same and exit with the same code.
 */
class SelfHostedParserTest extends GazLangTestCase
{
    private const PARSER = 'selfhost/ast.gaz';

    /**
     * Where the corpus is: selfhost/ too, so the ported parser is also checked on its own source
     */
    private const CORPUS = ['examples', 'lib', 'selfhost', 'tests'];

    /**
     * The driver, compiled once: parsing and compiling the parser again for every corpus file
     * would cost more than parsing the files does
     */
    private static ?Program $program = null;

    /**
     * Every .gaz file the parsers are compared on, keyed by path relative to the project root
     */
    public static function corpus(): array
    {
        $files = [];
        foreach (self::CORPUS as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT."/{$dir}")) as $path) {
                if (str_ends_with($path, '.gaz')) {
                    $file = substr($path, strlen(self::ROOT) + 1);
                    $files[$file] = [$file];
                }
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * The expected --ast output and exit code for a file, from the PHP parser in-process
     *
     * @return array{0: string, 1: int}
     */
    private static function phpAst(string $file): array
    {
        $cwd = getcwd();
        // Include paths are shown relative to the working directory
        chdir(self::ROOT);
        try {
            return [Dumper::dump((new Parser(new Lexer(file_get_contents($file)), $file))->parse()), 0];
        } catch (GazLangError $e) {
            return ["Error: {$e->getMessage()}\n", 1];
        } finally {
            chdir($cwd);
        }
    }

    /**
     * The parser's own cases, which are named after what they do
     */
    public static function parserCorpus(): array
    {
        return array_filter(self::corpus(), fn (string $file) => str_starts_with($file, 'tests/parser_corpus/'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @dataProvider parserCorpus
     */
    public function test_corpus_files_named_error_are_exactly_the_ones_that_fail(string $file)
    {
        [, $exit_code] = self::phpAst($file);

        $this->assertSame(str_starts_with(basename($file), 'error_') ? 1 : 0, $exit_code);
    }

    public function test_in_process_expectation_matches_the_cli()
    {
        foreach (['tests/parser_corpus/precedence.gaz', 'tests/parser_corpus/error_missing_operand.gaz'] as $file) {
            exec(sprintf('cd %s && %s bin/gazlang --ast -f %s 2>&1', escapeshellarg(self::ROOT), escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $exit_code);

            $this->assertSame(self::phpAst($file), [implode("\n", $output)."\n", $exit_code], $file);
            $output = [];
        }
    }

    /**
     * GazLang can't ask which builtins exist, so the parser carries a copy of the table to check calls against
     */
    public function test_the_parsers_builtin_table_is_the_runtimes()
    {
        $output = $this->executeCode('include "selfhost/parser.gaz"; echo Parser(Lexer("")).builtins;');

        $this->assertSame(Values::toString(new MapValue(Builtins::ARITIES)), rtrim($output));
    }

    /**
     * The corpus runs on the VM only, which is what keeps it fast, so a few files go through the interpreter as well
     */
    public function test_self_hosted_parser_gives_the_same_tree_on_the_interpreter()
    {
        foreach (['precedence', 'literals', 'captures', 'interpolation', 'match', 'error_missing_operand', 'error_lexer_error_has_the_file', 'error_lambda_duplicate_parameter'] as $name) {
            $file = "tests/parser_corpus/{$name}.gaz";

            $this->assertSame(self::phpAst($file), $this->runProgram(self::PARSER, [$file]), $file);
        }
    }

    /**
     * @dataProvider corpus
     */
    public function test_self_hosted_parser_matches_the_php_parser(string $file)
    {
        self::$program ??= self::compileProgram(self::PARSER);
        [$output, $exit_code] = $this->runCompiled(self::$program, [$file]);

        [$expected, $expected_exit_code] = self::phpAst($file);
        $this->assertSame($expected, $output, "Tree differs for {$file}");
        $this->assertSame($expected_exit_code, $exit_code, "Exit code differs for {$file}");
    }
}
