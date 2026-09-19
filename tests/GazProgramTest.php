<?php

namespace GazLang\Tests;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Runs the GazLang test programs: every file under tests/gaz ending in _test.gaz must print
 * exactly the contents of the matching _test.expected file
 *
 * Other .gaz files under tests/gaz are helpers for tests to include (see check.gaz).
 */
class GazProgramTest extends GazLangTestCase
{
    /**
     * Every test program, keyed by path relative to the project root
     */
    public static function programs(): array
    {
        $programs = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT.'/tests/gaz'));
        foreach ($files as $path) {
            if (str_ends_with($path, '_test.gaz')) {
                $file = substr($path, strlen(self::ROOT) + 1);
                $programs[$file] = [$file];
            }
        }
        ksort($programs);

        return $programs;
    }

    /**
     * @dataProvider programs
     */
    public function test_program_prints_its_expected_output(string $file)
    {
        [$output] = $this->runProgram($file);

        $expected = self::ROOT.'/'.substr($file, 0, -strlen('.gaz')).'.expected';
        $this->assertFileExists($expected, "Missing expected output for {$file}; it printed:\n{$output}");
        $this->assertSame(file_get_contents($expected), $output, $file);
    }

    public function test_in_process_run_matches_the_cli()
    {
        $file = 'tests/fixtures/include/runtime.gaz';
        exec(sprintf('cd %s && %s bin/gazlang-php -f %s 2>&1', escapeshellarg(self::ROOT), escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $exit_code);

        $this->assertSame([implode("\n", $output)."\n", $exit_code], $this->runProgram($file));
    }
}
