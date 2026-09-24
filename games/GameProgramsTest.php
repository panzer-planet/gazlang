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

    public function test_the_game_runs_on_a_terminal_and_leaves_it_as_it_found_it()
    {
        // 2 is the squad, c begins a matchday, s skips to full time, c goes on, 5 is the table, q quits
        $ran = $this->fileOnTerminal('games/football/main.gaz', bin2hex('2csc5q'), null, ['1']);

        $this->assertSame(0, $ran['code'], $ran['out']);
        $this->assertStringContainsString('Riverside FC', $ran['out']);
        $this->assertStringContainsString('League table', $ran['out']);
        $this->assertSame(['echo' => false, 'icanon' => false, 'isig' => false], $ran['during']);
        $this->assertSame(['echo' => true, 'icanon' => true, 'isig' => true], $ran['after']);
        // it ends on the main screen again, with the cursor back
        $this->assertStringEndsWith("\e[?25h\e[?1049l", $ran['out']);
    }

    public function test_the_game_says_when_it_has_no_terminal_or_a_bad_seed()
    {
        [$out, $err, $code] = self::gazlang(['-f', 'games/football/main.gaz']);
        $this->assertSame(['', "Error: The football manager needs a terminal\n", 1], [$out, $err, $code]);

        $ran = $this->fileOnTerminal('games/football/main.gaz', '', null, ['nonsense']);
        $this->assertSame(1, $ran['code']);
        $this->assertStringContainsString('where SEED is a whole number', $ran['out']);
    }

    public function test_a_match_moves_by_itself_while_nobody_presses_a_key()
    {
        // c begins a matchday and then nothing is pressed: time passes at a minute a quarter of a second,
        // so in two and a half seconds the first goal of seed 1's match (in the fourth minute) is on the screen
        $ran = $this->fileOnTerminal('games/football/main.gaz', bin2hex('c'), 'INT', ['1'], 2.5);

        $this->assertSame(-2, $ran['code']);
        $this->assertStringContainsString('▶ x1', $ran['out']);
        $this->assertStringContainsString('GOAL!', $ran['out']);
        $this->assertSame(['echo' => true, 'icanon' => true, 'isig' => true], $ran['after']);
    }

    public function test_a_match_keeps_moving_while_a_key_is_held_down()
    {
        // c begins the matchday, then j (which does nothing in a match) is typed every 50ms for two and a half seconds,
        // as a key held down repeats: the beat is a quarter of a second, and a wait that started again with each key
        // would never end. The first goal of seed 1's match is in the fourth minute, a second in.
        $ran = $this->fileOnTerminal('games/football/main.gaz', bin2hex('c'), 'INT', ['1'], 0.3, bin2hex('j').':0.05:2.5');

        $this->assertSame(-2, $ran['code']);
        $this->assertStringContainsString('GOAL!', $ran['out']);
    }
}
