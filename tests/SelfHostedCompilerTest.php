<?php

namespace GazLang\Tests;

use GazLang\CodeGenerator\CodeGenerator;
use GazLang\CodeGenerator\Program;
use GazLang\GazLangError;
use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Checks the GazLang code generator (selfhost/codegen.gaz, run by selfhost/gazlang.gaz) against the PHP one, which is the spec
 *
 * For every corpus file the PHP compiler's `gazlang -c` output is the expected output: the
 * bytecode file, or for a file that doesn't parse "Error: <message> at FILE:N" and exit code 1.
 * The self-hosted compiler is run as `gazlang -f selfhost/gazlang.gaz -- code FILE` (on the C
 * VM, see CVM::driver()) and must print exactly the same and exit with the same code, and so
 * must `... -- code < FILE` against `gazlang -c < FILE`, which has no file to show.
 */
class SelfHostedCompilerTest extends GazLangTestCase
{
    /**
     * The driver, compiled once
     */
    private static ?Program $program = null;

    /**
     * The driver's output and exit code by file, run on the C VM for the whole corpus at once
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private static array $results = [];

    /**
     * The same for the code generator's own corpus, piped
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private static array $piped = [];

    /**
     * Every .gaz file the compilers are compared on, keyed by path relative to the project root
     */
    public static function corpus(): array
    {
        $files = [];
        foreach (['examples', 'lib', 'selfhost', 'tests'] as $dir) {
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
     * The code generator's own cases, which always run
     */
    public static function compilerCorpus(): array
    {
        return array_filter(self::corpus(), fn (string $file) => str_starts_with($file, 'tests/codegen_corpus/'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @dataProvider compilerCorpus
     */
    public function test_corpus_files_named_error_are_exactly_the_ones_that_fail(string $file)
    {
        [, $exit_code] = self::phpCode($file);

        $this->assertSame(str_starts_with(basename($file), 'error_') ? 1 : 0, $exit_code);
    }

    public function test_in_process_expectation_matches_the_cli()
    {
        foreach (['tests/codegen_corpus/expressions.gaz', 'examples/functions.gaz'] as $file) {
            exec(sprintf('cd %s && %s bin/gazlang -c -f %s 2>&1', escapeshellarg(self::ROOT), escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $exit_code);

            $this->assertSame(self::phpCode($file), [implode("\n", $output)."\n", $exit_code], $file);
            $output = [];
        }
    }

    /**
     * The corpus runs on the C VM only, which is what keeps it fast, so a few files go through the interpreter as well
     */
    public function test_self_hosted_compiler_gives_the_same_bytecode_on_the_interpreter()
    {
        foreach (['expressions', 'statements', 'functions', 'classes'] as $name) {
            $file = "tests/codegen_corpus/{$name}.gaz";

            $this->assertSame(self::phpCode($file), $this->runProgram(CVM::DRIVER, ['code', $file]), $file);
        }
    }

    /**
     * @dataProvider corpus
     */
    public function test_self_hosted_compiler_matches_the_php_compiler(string $file)
    {
        $this->assertSameCode($file);
    }

    /**
     * Paths in the bytecode are relative to the main file's directory, so where the main file is
     * and where it is compiled from both show in the output
     */
    public static function placesToCompileFrom(): array
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
     * @dataProvider placesToCompileFrom
     */
    public function test_self_hosted_compiler_matches_the_php_compiler_from_anywhere(string $cwd, string $file)
    {
        self::$program ??= self::compileProgram(CVM::DRIVER);

        $this->assertSame(self::phpCode($file, $cwd), $this->runCompiled(self::$program, ['code', $file], $cwd));
    }

    /**
     * Piped, only the working directory matters, which includes are relative to
     *
     * @dataProvider placesToCompileFrom
     */
    public function test_self_hosted_compiler_matches_the_php_compiler_on_piped_input_from_anywhere(string $cwd, string $file)
    {
        $this->assertSame(self::phpCode($file, $cwd, piped: true), CVM::driver('code', [$file], true, $cwd)[$file]);
    }

    /**
     * Piped source has no file: its locations are `@ line` records, and its includes are
     * relative to the working directory
     *
     * @dataProvider compilerCorpus
     */
    public function test_self_hosted_compiler_matches_the_php_compiler_on_piped_input(string $file)
    {
        self::$piped = self::$piped ?: CVM::driver('code', array_keys(self::compilerCorpus()), piped: true);
        $this->assertSameCode($file, self::$piped[$file], piped: true);
    }

    private function assertSameCode(string $file, ?array $result = null, bool $piped = false): void
    {
        self::$results = self::$results ?: CVM::driver('code', array_keys(self::corpus()));
        [$output, $exit_code] = $result ?? self::$results[$file];

        [$expected, $expected_exit_code] = self::phpCode($file, piped: $piped);
        $this->assertSameText($expected, $output, "Bytecode differs for {$file}");
        $this->assertSame($expected_exit_code, $exit_code, "Exit code differs for {$file}");
    }

    /**
     * The expected -c output and exit code for a file, from the PHP compiler in-process
     *
     * @param  string  $in  The working directory, which paths are resolved from
     * @param  bool  $piped  Whether the file is read as piped source, with no path
     * @return array{0: string, 1: int}
     */
    private static function phpCode(string $file, string $in = self::ROOT, bool $piped = false): array
    {
        $cwd = getcwd();
        chdir($in);
        try {
            $path = $piped ? null : $file;
            $parser = new Parser(new Lexer(file_get_contents($file)), $path);

            return [(new CodeGenerator($parser->parse()))->compile()->write($path), 0];
        } catch (GazLangError $e) {
            return ["Error: {$e->getMessage()}\n", 1];
        } finally {
            chdir($cwd);
        }
    }
}
