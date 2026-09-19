<?php

namespace GazLang\Tests;

/**
 * Checks the GazLang code generator (selfhost/codegen.gaz, run by selfhost/gazlang.gaz) on its corpus
 *
 * Each tests/codegen_corpus/X.gaz has what `gazlang -c` must print for it in X.code: the bytecode
 * file, or for a file that doesn't parse "Error: <message> at FILE:N" and exit code 1; and in
 * X.piped.code what `gazlang -c < X.gaz` must print, which has no file to show. The self-hosted
 * compiler is run as `gazlang -f selfhost/gazlang.gaz -- code FILE` on the C VM (see
 * CVM::driver()). Beyond the corpus, the compiler compiling itself to the same bytecode twice
 * (CVMTest, make compiler) checks it on the largest program there is.
 */
class SelfHostedCompilerTest extends GazLangTestCase
{
    private const CORPUS = 'tests/codegen_corpus';

    /**
     * The driver's output and exit code by file, run on the C VM for the whole corpus at once
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private static array $results = [];

    /**
     * The same, piped
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private static array $piped = [];

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
    public function test_self_hosted_compiler_prints_the_expected_bytecode(string $file)
    {
        self::$results = self::$results ?: CVM::driver('code', array_keys(self::corpus()));
        $this->assertPortPrints(substr($file, 0, -3).'code', self::$results[$file], "Bytecode for {$file}");
    }

    /**
     * @dataProvider corpus
     */
    public function test_corpus_files_named_error_are_exactly_the_ones_that_fail(string $file)
    {
        self::$results = self::$results ?: CVM::driver('code', array_keys(self::corpus()));

        $this->assertSame(str_starts_with(basename($file), 'error_') ? 1 : 0, self::$results[$file][1]);
    }

    /**
     * Piped source has no file: its locations are `@ line` records, and its includes are
     * relative to the working directory
     *
     * @dataProvider corpus
     */
    public function test_self_hosted_compiler_prints_the_expected_bytecode_on_piped_input(string $file)
    {
        self::$piped = self::$piped ?: CVM::driver('code', array_keys(self::corpus()), piped: true);
        $this->assertPortPrints(substr($file, 0, -3).'piped.code', self::$piped[$file], "Piped bytecode for {$file}");
    }

    /**
     * Paths in the bytecode are relative to the main file's directory, so where the main file is
     * and where it is compiled from both show in the output; the parser's places
     */
    public static function placesToCompileFrom(): array
    {
        return SelfHostedParserTest::places();
    }

    /**
     * @dataProvider placesToCompileFrom
     */
    public function test_self_hosted_compiler_prints_the_expected_bytecode_from_anywhere(string $cwd, string $file, string $name)
    {
        $this->assertPortPrints("tests/parser_corpus/places/{$name}.code", CVM::driver('code', [$file], false, $cwd)[$file], $name);
    }

    /**
     * Piped, only the working directory matters, which includes are relative to
     *
     * @dataProvider placesToCompileFrom
     */
    public function test_self_hosted_compiler_prints_the_expected_bytecode_on_piped_input_from_anywhere(string $cwd, string $file, string $name)
    {
        $this->assertPortPrints("tests/parser_corpus/places/{$name}.piped.code", CVM::driver('code', [$file], true, $cwd)[$file], "{$name}, piped");
    }

    public function test_every_expected_file_has_its_program()
    {
        $this->assertSame([], self::strayExpectations(self::CORPUS, ['piped.code', 'code']));
    }
}
