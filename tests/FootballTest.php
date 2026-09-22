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
