<?php

namespace GazLang\Games;

use GazLang\Tests\GazLangTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Runs the games' GazLang test programs: every games/NAME/tests/X_test.gaz must print exactly
 * the contents of X_test.expected next to it
 *
 * They are their own PHPUnit suite (`vendor/bin/phpunit --testsuite games`, and `--testsuite core`
 * for the language alone) so that a game can be worked on without the language's two minutes, and
 * a change to a game's rules never re-records anything of the language's. The test programs check
 * what stays true however the game is tuned, not what a seeded run prints.
 */
class GameProgramsTest extends GazLangTestCase
{
    /**
     * Every game's test programs, keyed by path relative to the project root
     *
     * @return array<string, array{string}>
     */
    public static function programs(): array
    {
        $programs = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT.'/games', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $path) {
            if (str_ends_with((string) $path, '_test.gaz') && preg_match('#/games/[^/]+/tests/[^/]+$#', (string) $path)) {
                $file = substr((string) $path, strlen(self::ROOT) + 1);
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

    public function test_there_are_test_programs_to_run()
    {
        $this->assertNotSame([], self::programs());
    }
}
