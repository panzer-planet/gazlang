<?php

namespace GazLang\Tests;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Checks the GazLang parser (compiler/parser.gaz, run by compiler/gazlang.gaz) on its corpus
 *
 * Each tests/parser_corpus/X.gaz has what `gaz --ast` must print for it in X.ast: the tree
 * as AST\Dumper prints it, or for a file that doesn't parse "Error: <message> at FILE:N" and exit
 * code 1; and in X.piped.ast what `gaz --ast < X.gaz` must print, which has no file to show.
 * The self-hosted parser is run as `gaz compiler/gazlang.gaz ast FILE` on the C VM (see
 * CVM::driver()).
 */
class SelfHostedParserTest extends GazLangTestCase
{
    private const CORPUS = 'tests/parser_corpus';

    /**
     * Where the cases run from other working directories keep what they must print
     */
    private const PLACES = 'tests/parser_corpus/places';

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
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT.'/'.self::CORPUS)) as $path) {
            if (str_ends_with($path, '.gaz')) {
                $file = substr($path, strlen(self::ROOT) + 1);
                $files[$file] = [$file];
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @dataProvider corpus
     */
    public function test_self_hosted_parser_prints_the_expected_tree(string $file)
    {
        self::$results = self::$results ?: CVM::driver('ast', array_keys(self::corpus()));
        $this->assertPortPrints(substr($file, 0, -3).'ast', self::$results[$file], "Tree for {$file}");
    }

    /**
     * @dataProvider corpus
     */
    public function test_corpus_files_named_error_are_exactly_the_ones_that_fail(string $file)
    {
        self::$results = self::$results ?: CVM::driver('ast', array_keys(self::corpus()));

        $this->assertSame(str_starts_with(basename($file), 'error_') ? 1 : 0, self::$results[$file][1]);
    }

    /**
     * Piped source has no file: its locations are line numbers only, and its includes are
     * relative to the working directory, so an include that works from a file fails piped
     *
     * @dataProvider corpus
     */
    public function test_self_hosted_parser_prints_the_expected_tree_on_piped_input(string $file)
    {
        self::$piped = self::$piped ?: CVM::driver('ast', array_keys(self::corpus()), piped: true);
        $this->assertPortPrints(substr($file, 0, -3).'piped.ast', self::$piped[$file], "Piped tree for {$file}");
    }

    /**
     * Where a file is, and where it is parsed from, changes how its includes are shown and not
     * what they are: run from elsewhere, or given by a path the corpus can't spell. Each case is
     * [working directory, file, the name its expected files have in PLACES]; the directories are
     * in the checkout, so what is printed is the same on every machine.
     */
    public static function placesToParseFrom(): array
    {
        return self::places();
    }

    /**
     * The places both the parser's and the compiler's harnesses run from
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function places(): array
    {
        $include = self::ROOT.'/tests/parser_corpus/include';
        $elsewhere = self::ROOT.'/vm/build/elsewhere';
        @mkdir($elsewhere, 0777, true);

        return [
            'main file given by its absolute path' => [self::ROOT, "{$include}/main.gaz", 'absolute_main'],
            'includes that climb out of the working directory' => ["{$include}/lib/deep", 'uses_parent_dir.gaz', 'climbing_includes'],
            'working directory below the main file' => ["{$include}/lib", '../main.gaz', 'below_the_main_file'],
            'working directory elsewhere' => [$elsewhere, "{$include}/symlink_is_the_file_it_points_to.gaz", 'elsewhere'],
        ];
    }

    /**
     * @dataProvider placesToParseFrom
     */
    public function test_self_hosted_parser_prints_the_expected_tree_from_anywhere(string $cwd, string $file, string $name)
    {
        $this->assertPortPrints(self::PLACES."/{$name}.ast", CVM::driver('ast', [$file], false, $cwd)[$file], $name);
    }

    /**
     * Piped, only the working directory matters, which includes are relative to
     *
     * @dataProvider placesToParseFrom
     */
    public function test_self_hosted_parser_prints_the_expected_tree_on_piped_input_from_anywhere(string $cwd, string $file, string $name)
    {
        $this->assertPortPrints(self::PLACES."/{$name}.piped.ast", CVM::driver('ast', [$file], true, $cwd)[$file], "{$name}, piped");
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

        try {
            [$output, $exit_code] = CVM::driver('ast', ['main.gaz'], false, $dir)['main.gaz'];
            $this->assertSame(0, $exit_code, $output);
            $this->assertSame(1, substr_count($output, 'FunctionDeclaration'), $output);
        } finally {
            unlink("{$dir}/lib/helper.gaz");
            unlink("{$dir}/main.gaz");
            rmdir("{$dir}/lib");
            rmdir($dir);
        }
    }

    public function test_every_expected_file_has_its_program()
    {
        $places = array_merge(...array_map(fn ($place) => ["{$place[2]}.ast", "{$place[2]}.piped.ast", "{$place[2]}.code", "{$place[2]}.piped.code"], array_values(self::places())));
        $recorded = array_map('basename', glob(self::ROOT.'/'.self::PLACES.'/*'));
        sort($places);
        sort($recorded);

        $stray = array_filter(self::strayExpectations(self::CORPUS, ['piped.ast', 'ast']), fn ($file) => ! str_starts_with($file, self::PLACES.'/'));
        $this->assertSame([], array_values($stray));
        $this->assertSame($places, $recorded, 'what '.self::PLACES.' holds');
    }
}
