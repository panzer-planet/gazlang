<?php

namespace GazLang\Tests;

use GazLang\AST\Dumper;
use GazLang\CodeGenerator\Program;
use GazLang\GazLangError;
use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Checks the GazLang parser (selfhost/parser.gaz, run by selfhost/gazlang.gaz) against the PHP parser, which is the spec
 *
 * For every corpus file the PHP parser's `gazlang --ast` output is the expected output: the
 * tree as AST\Dumper prints it, or for a file that doesn't parse "Error: <message> at FILE:N"
 * and exit code 1. The self-hosted parser is run as `gazlang -f selfhost/gazlang.gaz -- ast FILE`
 * (on the C VM, see CVM::driver()) and must print exactly the same and exit with the same code,
 * and so must `... -- ast < FILE` against `gazlang --ast < FILE`, which has no file to show.
 */
class SelfHostedParserTest extends GazLangTestCase
{
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
     * The driver's output and exit code by file, run on the C VM for the whole corpus at once
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private static array $results = [];

    /**
     * The same for the parser's own corpus, piped
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private static array $piped = [];

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
            exec(sprintf('cd %s && %s bin/gazlang-php --ast -f %s 2>&1', escapeshellarg(self::ROOT), escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $exit_code);

            $this->assertSame(self::phpAst($file), [implode("\n", $output)."\n", $exit_code], $file);
            $output = [];
        }
    }

    /**
     * The corpus runs on the C VM only, which is what keeps it fast, so a few files go through the interpreter as well
     */
    public function test_self_hosted_parser_gives_the_same_tree_on_the_interpreter()
    {
        foreach (['precedence', 'literals', 'captures', 'interpolation', 'match', 'error_missing_operand', 'error_lexer_error_has_the_file', 'error_lambda_duplicate_parameter'] as $name) {
            $file = "tests/parser_corpus/{$name}.gaz";

            $this->assertSame(self::phpAst($file), $this->runProgram(CVM::DRIVER, ['ast', $file]), $file);
        }
    }

    /**
     * @dataProvider corpus
     */
    public function test_self_hosted_parser_matches_the_php_parser(string $file)
    {
        $this->assertSameTree($file);
    }

    /**
     * Piped source has no file: its locations are line numbers only, and its includes are
     * relative to the working directory, so an include that works from a file fails piped
     *
     * @dataProvider parserCorpus
     */
    public function test_self_hosted_parser_matches_the_php_parser_on_piped_input(string $file)
    {
        self::$piped = self::$piped ?: CVM::driver('ast', array_keys(self::parserCorpus()), piped: true);
        $this->assertSameTree($file, self::$piped[$file], piped: true);
    }

    private function assertSameTree(string $file, ?array $result = null, bool $piped = false): void
    {
        self::$results = self::$results ?: CVM::driver('ast', array_keys(self::corpus()));
        [$output, $exit_code] = $result ?? self::$results[$file];

        [$expected, $expected_exit_code] = self::phpAst($file, piped: $piped);
        $this->assertSameText($expected, $output, "Tree differs for {$file}");
        $this->assertSame($expected_exit_code, $exit_code, "Exit code differs for {$file}");
    }

    /**
     * Where a file is, and where it is parsed from, changes how its includes are shown and not
     * what they are: run from elsewhere, or given by a path the corpus can't spell, which the
     * port could only get right once it could ask for real paths and the working directory
     */
    public static function placesToParseFrom(): array
    {
        $include = self::ROOT.'/tests/parser_corpus/include';

        return [
            'main file given by its absolute path' => [self::ROOT, "{$include}/main.gaz"],
            'includes that climb out of the working directory' => ["{$include}/lib/deep", 'uses_parent_dir.gaz'],
            'working directory below the main file' => ["{$include}/lib", '../main.gaz'],
            'working directory elsewhere' => [sys_get_temp_dir(), "{$include}/symlink_is_the_file_it_points_to.gaz"],
        ];
    }

    /**
     * @dataProvider placesToParseFrom
     */
    public function test_self_hosted_parser_matches_the_php_parser_from_anywhere(string $cwd, string $file)
    {
        self::$program ??= self::compileProgram(CVM::DRIVER);

        $this->assertSame(self::phpAst($file, $cwd), $this->runCompiled(self::$program, ['ast', $file], $cwd));
    }

    /**
     * Piped, only the working directory matters, which includes are relative to
     *
     * @dataProvider placesToParseFrom
     */
    public function test_self_hosted_parser_matches_the_php_parser_on_piped_input_from_anywhere(string $cwd, string $file)
    {
        $this->assertSame(self::phpAst($file, $cwd, piped: true), CVM::driver('ast', [$file], true, $cwd)[$file]);
    }

    /**
     * One file included by a relative and by an absolute path is included once. The absolute
     * path is this machine's, so the program is written where it runs.
     */
    public function test_a_file_included_by_a_relative_and_an_absolute_path_is_included_once()
    {
        $dir = sys_get_temp_dir().'/gazlang_include_'.getmypid();
        mkdir("{$dir}/lib", 0777, true);
        file_put_contents("{$dir}/lib/helper.gaz", "fn helper() { return 1; }\n");
        file_put_contents("{$dir}/main.gaz", "include \"lib/helper.gaz\";\ninclude \"{$dir}/lib/helper.gaz\";\necho helper();\n");
        self::$program ??= self::compileProgram(CVM::DRIVER);

        try {
            [$expected, $exit_code] = self::phpAst('main.gaz', $dir);
            $this->assertSame(0, $exit_code, $expected);
            $this->assertSame([$expected, 0], $this->runCompiled(self::$program, ['ast', 'main.gaz'], $dir));
        } finally {
            unlink("{$dir}/lib/helper.gaz");
            unlink("{$dir}/main.gaz");
            rmdir("{$dir}/lib");
            rmdir($dir);
        }
    }

    /**
     * The expected --ast output and exit code for a file, from the PHP parser in-process
     *
     * @param  string  $in  The working directory, which include paths are shown relative to
     * @param  bool  $piped  Whether the file is read as piped source, with no path
     * @return array{0: string, 1: int}
     */
    private static function phpAst(string $file, string $in = self::ROOT, bool $piped = false): array
    {
        $cwd = getcwd();
        // Include paths are shown relative to the working directory
        chdir($in);
        try {
            return [Dumper::dump((new Parser(new Lexer(file_get_contents($file)), $piped ? null : $file))->parse()), 0];
        } catch (GazLangError $e) {
            return ["Error: {$e->getMessage()}\n", 1];
        } finally {
            chdir($cwd);
        }
    }
}
