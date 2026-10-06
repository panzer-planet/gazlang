<?php

namespace GazLang\Tests;

/**
 * lib/date.gaz's zones against PHP's DateTime, which shares no code with them: PHP carries its
 * own copy of the time zone database and reads it with its own C. Each zone is read by gaz from
 * the TZif file checked in under tests/fixtures/zoneinfo, so a machine's own copy never matters;
 * the zones are ones whose rules haven't changed between the database PHP carries and the one
 * the files came from.
 *
 * Times either side of every change of offset PHP knows of, and random times from 1850 to 2200
 * (past 2037, where a file's list ends and its rule takes over), must show the same date, time
 * of day, offset, daylight saving flag and abbreviation; and clock readings around each change
 * and at random must be the same times: the earlier and the later of the two a reading is
 * where the clocks went back or forward, PHP's own reading being one of them.
 */
class DateTest extends GazLangTestCase
{
    private const ZONES = [
        'Europe/London', 'Africa/Johannesburg', 'America/New_York', 'Australia/Lord_Howe',
        'Pacific/Apia', 'America/Sao_Paulo', 'Asia/Kolkata', 'UTC',
    ];

    private const FIRST = -3786825600;  // 1850-01-01

    private const LAST = 7258118400;    // 2200-01-01

    private const RANDOM = 400;

    /**
     * Seconds either side of a change that a time or a clock reading is tried at
     */
    private const AROUND = [-86400, -3601, -3600, -1800, -1, 0, 1, 1799, 1800, 3599, 3600, 7200, 86400];

    /**
     * @return array{list<int>, list<int>} times and clock readings (seconds as if in UTC) to try
     */
    private static function samples(string $name): array
    {
        $zone = new \DateTimeZone($name);
        $times = [];
        $walls = [];
        foreach ($zone->getTransitions(self::FIRST, self::LAST) as $i => $transition) {
            if ($i === 0) {
                continue; // the first is the start of the range, not a change
            }
            $before = $zone->getOffset(new \DateTime('@'.($transition['ts'] - 1)));
            foreach (self::AROUND as $delta) {
                $times[] = $transition['ts'] + $delta;
                $walls[] = $transition['ts'] + $before + $delta;
            }
        }
        for ($n = 0; $n < self::RANDOM; $n++) {
            $times[] = mt_rand(self::FIRST, self::LAST);
            $walls[] = mt_rand(self::FIRST, self::LAST);
        }

        return [$times, $walls];
    }

    /**
     * A line per time as PHP shows it in $zone, as the program below writes them
     */
    private static function shown(\DateTimeZone $zone, int $time): string
    {
        $moment = (new \DateTime('@'.$time))->setTimezone($zone);

        return implode(' ', [$time, $moment->format('Y-m-d H:i:s'), $moment->getOffset(), $moment->format('I'), $moment->format('T')]);
    }

    /**
     * A line per clock reading in $zone, as the program below writes them: the earlier and the
     * later of the times it is with the offsets PHP has a day before and a day after it, which
     * are the same when only one of them fits. PHP's own reading of the text must be one of
     * them: the only one, the later when the clocks went forward, and either when they went
     * back (PHP takes the later for most changes, but not all).
     *
     * @param  array<string, int>  $counts  how many readings were found once, twice and never
     */
    private function reading(\DateTimeZone $zone, int $wall, array &$counts): string
    {
        $offset = fn (int $time) => $zone->getOffset(new \DateTime('@'.$time));
        $readings = array_values(array_unique([$wall - $offset($wall - 86400), $wall - $offset($wall + 86400)]));
        sort($readings);
        $fitting = array_values(array_filter($readings, fn ($time) => $offset($time) === $wall - $time));
        $text = gmdate('Y-m-d H:i:s', $wall);
        $php = (new \DateTime($text, $zone))->getTimestamp();
        $where = "{$text} in {$zone->getName()}";
        if (count($fitting) === 1) {
            $counts['once']++;
            $this->assertSame($fitting[0], $php, $where);

            return "{$text} {$fitting[0]} {$fitting[0]}";
        }
        $earlier = $readings[0];
        $later = end($readings);
        if (count($fitting) === 0) {
            $counts['never']++;
            $this->assertSame($later, $php, $where);
        } else {
            $counts['twice']++;
            $this->assertContains($php, [$earlier, $later], $where);
        }

        return "{$text} {$earlier} {$later}";
    }

    public function test_zones_agree_with_php_on_what_the_clocks_show_and_when()
    {
        mt_srand(20261005);
        $program = 'import "std/date.gaz";'."\n";
        $expected = [];
        $counts = ['once' => 0, 'twice' => 0, 'never' => 0];
        foreach (self::ZONES as $name) {
            [$times, $walls] = self::samples($name);
            $zone = new \DateTimeZone($name);
            $program .= '$zone = date::zone("'.$name.'", "tests/fixtures/zoneinfo");'."\n"
                .'foreach (['.implode(', ', $times).'] as $time) {
                    $m = $zone.at($time);
                    echo "{$time} " .. date::iso($m.days) .. " " .. $m.clock() .. " {$m.offset} " .. ($m.dst ? 1 : 0) .. " {$m.abbreviation}";
                }
                foreach (['.implode(', ', $walls).'] as $wall) {
                    $days = intdiv($wall - ($wall % 86400 + 86400) % 86400, 86400);
                    $reading = date::utc().at($wall);
                    $seconds = $wall - $days * 86400;
                    echo date::iso($days) .. " " .. $reading.clock() .. " " .. $zone.time($days, $seconds, date::Resolution::Earlier) .. " " .. $zone.time($days, $seconds, date::Resolution::Later);
                }'."\n";
            foreach ($times as $time) {
                $expected[] = self::shown($zone, $time);
            }
            foreach ($walls as $wall) {
                $expected[] = $this->reading($zone, $wall, $counts);
            }
        }
        // Not executeCode(): the program is generated, and would be recorded for the sanitized harness
        $actual = self::succeed([], $program);
        $this->assertSameText(implode("\n", $expected)."\n", $actual, 'gaz and PHP disagree');
        // Changes of offset must be there in numbers, or this checks little
        $this->assertGreaterThan(500, $counts['twice']);
        $this->assertGreaterThan(500, $counts['never']);
    }
}
