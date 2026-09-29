<?php

namespace GazLang\Tests;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Runs the GazLang test programs: every file under tests/gaz ending in _test.gaz must print
 * exactly the contents of the matching _test.expected file
 *
 * A test prints its results with test::expect() from std/test.gaz (`include "std/test.gaz" use expect
 * as check;`), so a failure is a readable diff against the expected file.
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
}
