<?php

namespace GazLang\Tests;

/**
 * examples/football.gaz with arguments: every invocation in the table must print what
 * tests/football/expected records for it, standard output and exit code (the run with none is
 * CVMTest's, as every example's is). GAZLANG_RECORD=1 records what it prints instead, for
 * review as a diff.
 */
class FootballTest extends GazLangTestCase
{
    /**
     * Each invocation's arguments
     *
     * @var array<string, list<string>>
     */
    public const CASES = [
        'a pyramid over three seasons' => ['6', '--seasons', '3', '--divisions', '2'],
        'seasons of one division' => ['4', '2030', '--seasons', '2'],
        'four divisions for a season' => ['4', '--divisions', '4'],
        'options before the clubs' => ['--divisions', '2', '3'],
        'an unknown option' => ['--season', '2'],
        'an option without its number' => ['--seasons'],
        'an option with a word' => ['--seasons', 'two'],
        'too many divisions' => ['--divisions', '5'],
        'no seasons' => ['--seasons', '0'],
        'too many arguments' => ['4', '2026', '9'],
        'too many clubs' => ['21'],
        'a saved career carried on' => ['--load', 'tests/football/fixtures/career.json', '--seasons', '2'],
        'no saved career' => ['--load', 'tests/football/fixtures/missing.json'],
        'a file that is not a career' => ['--load', 'tests/football/fixtures/not_a_career.json'],
        'a file that is not JSON' => ['--load', 'tests/football/fixtures/not_json.json'],
        'clubs with a loaded career' => ['6', '--load', 'tests/football/fixtures/career.json'],
        'save without a file' => ['--save'],
    ];

    public static function cases(): array
    {
        return array_map(fn ($name) => [$name], array_combine(array_keys(self::CASES), array_keys(self::CASES)));
    }

    /**
     * @dataProvider cases
     */
    public function test_football_prints_what_is_recorded(string $name)
    {
        [$out, $code] = $this->runProgram('examples/football.gaz', self::CASES[$name]);
        $base = self::expected($name);
        if (getenv('GAZLANG_RECORD') !== false) {
            CVM::recordAt($base, [$out, '', $code]);
        }
        $expected = CVM::recorded($base);
        $this->assertNotNull($expected, "{$name}: nothing recorded; GAZLANG_RECORD=1 vendor/bin/phpunit --filter FootballTest");

        $this->assertSame($expected, [CVM::portable($out), '', $code], $name);
    }

    public function test_a_saved_career_carries_on_as_if_it_never_stopped()
    {
        // The summer after the last season is played before saving, and each season is seeded
        // on its own, so two seasons saved and two loaded print what four in one run do. Two
        // loaded, so a summer is played from what was loaded: only a summer spends money
        $file = sys_get_temp_dir().'/football_career_'.getmypid().'.json';
        try {
            [$whole] = $this->runProgram('examples/football.gaz', ['6', '--seasons', '4', '--divisions', '2']);
            [$first, $code] = $this->runProgram('examples/football.gaz', ['6', '--seasons', '2', '--divisions', '2', '--save', $file]);
            $this->assertSame(0, $code, $first);
            [$rest, $code] = $this->runProgram('examples/football.gaz', ['--load', $file, '--seasons', '2']);
            $this->assertSame(0, $code, $rest);

            $third = strpos($whole, 'Season 2028/29');
            $this->assertSame(substr($whole, $third), $rest);
            // The first run printed the honours so far after its two seasons; the rest is the same
            $this->assertSame(substr($whole, 0, $third), substr($first, 0, strpos($first, "Honours\n")));
        } finally {
            @unlink($file);
        }
    }

    public function test_every_recording_belongs_to_a_case()
    {
        $recorded = array_unique(array_map(fn ($path) => preg_replace('/\.(stdout|stderr|exit)$/', '', $path), glob(self::ROOT.'/tests/football/expected/*')));
        $this->assertSame([], array_values(array_diff($recorded, array_map(self::expected(...), array_keys(self::CASES)))));
    }

    /**
     * Where a case's recording is, without its suffix: its name with dashes for spaces
     */
    private static function expected(string $name): string
    {
        return self::ROOT.'/tests/football/expected/'.str_replace(' ', '-', $name);
    }
}
