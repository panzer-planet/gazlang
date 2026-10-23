<?php

// Writes vm/unicode_tables.h, the case tables utf8_upper(), utf8_lower() and utf8_fold() read and
// the width tables utf8_width() reads (vm/unicode.c), from the Unicode Character Database of the
// version below, downloaded once into vm/build/unicode/. The tables are checked in, so building gaz
// never needs the network; run this only to move to a new version of Unicode, then review the diff
// and run tests/Utf8CaseTest.php and tests/Utf8WidthTest.php.
//   php tools/unicode.php
//
// What goes in, from the standard's own files:
//   upper, lower  UnicodeData.txt's simple mappings, replaced by SpecialCasing.txt's full ones
//                 that hold in every language (no condition), so ß uppercases to SS
//   fold          CaseFolding.txt's C and F lines, the full folding
//   cased, case-ignorable
//                 DerivedCoreProperties.txt's Cased and Case_Ignorable, which say when a capital
//                 sigma ends a word (SpecialCasing.txt's one condition that isn't a language's)
//   zero width    the code points that take no terminal column: UnicodeData.txt's nonspacing and
//                 enclosing marks (Mn, Me) and format characters (Cf), and HangulSyllableType.txt's
//                 vowels and trailing consonants (V, T), which join the leading consonant before them
//   wide          EastAsianWidth.txt's W and F, which take two columns

const VERSION = '17.0.0';

$root = dirname(__DIR__);
$dir = "{$root}/vm/build/unicode/".VERSION;

/**
 * A file of the database, downloaded the first time
 */
function ucd(string $dir, string $name): string
{
    $path = "{$dir}/{$name}";
    if (! is_file($path)) {
        @mkdir($dir, 0777, true);
        $text = file_get_contents('https://www.unicode.org/Public/'.VERSION."/ucd/{$name}");
        if ($text === false) {
            fwrite(STDERR, "Could not download {$name}\n");
            exit(1);
        }
        file_put_contents($path, $text);
    }

    return file_get_contents($path);
}

/**
 * A file's lines without comments, each split at its semicolons and trimmed
 *
 * @return list<list<string>>
 */
function records(string $text): array
{
    $records = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim(explode('#', $line, 2)[0]);
        if ($line !== '') {
            $records[] = array_map(trim(...), explode(';', $line));
        }
    }

    return $records;
}

/**
 * Space-separated hex code points as ints
 *
 * @return list<int>
 */
function codes(string $hex): array
{
    return $hex === '' ? [] : array_map(hexdec(...), explode(' ', $hex));
}

$upper = [];
$lower = [];
$zero_width = [];
$range_start = null;
foreach (records(ucd($dir, 'UnicodeData.txt')) as $fields) {
    $code = hexdec($fields[0]);
    if ($fields[12] !== '') {
        $upper[$code] = codes($fields[12]);
    }
    if ($fields[13] !== '') {
        $lower[$code] = codes($fields[13]);
    }
    /*
     * A block of one category is two lines, "<..., First>" and "<..., Last>", with the
     * category on both; none is a mark or a format character today, but the reading is the file's
     */
    if (str_ends_with($fields[1], ', First>')) {
        $range_start = $code;

        continue;
    }
    if (in_array($fields[2], ['Mn', 'Me', 'Cf'], true)) {
        $zero_width[] = [$range_start ?? $code, $code];
    }
    $range_start = null;
}
foreach (records(ucd($dir, 'HangulSyllableType.txt')) as $fields) {
    if ($fields[1] === 'V' || $fields[1] === 'T') {
        $zero_width[] = bounds($fields[0]);
    }
}

$wide = [];
foreach (records(ucd($dir, 'EastAsianWidth.txt')) as $fields) {
    if ($fields[1] === 'W' || $fields[1] === 'F') {
        $wide[] = bounds($fields[0]);
    }
}
foreach (records(ucd($dir, 'SpecialCasing.txt')) as $fields) {
    // code; lower; title; upper; and a condition list only where one applies
    if (count($fields) > 4 && $fields[4] !== '') {
        continue;
    }
    $code = hexdec($fields[0]);
    $upper[$code] = codes($fields[3]);
    $lower[$code] = codes($fields[1]);
}

$fold = [];
foreach (records(ucd($dir, 'CaseFolding.txt')) as [$code, $status, $mapping]) {
    if ($status === 'C' || $status === 'F') {
        $fold[hexdec($code)] = codes($mapping);
    }
}

$properties = ['Cased' => [], 'Case_Ignorable' => []];
foreach (records(ucd($dir, 'DerivedCoreProperties.txt')) as $fields) {
    if (isset($properties[$fields[1]])) {
        $properties[$fields[1]][] = bounds($fields[0]);
    }
}

/**
 * A record's code point or range ("0041" or "0041..005A") as its first and last code point
 *
 * @return array{int, int}
 */
function bounds(string $field): array
{
    $bounds = explode('..', $field);

    return [hexdec($bounds[0]), hexdec($bounds[1] ?? $bounds[0])];
}

/**
 * A mapping table in C: only the characters it changes, in order, so a binary search finds one
 *
 * @param  array<int, list<int>>  $mappings
 */
function mapping_table(string $name, array $mappings): string
{
    ksort($mappings);
    $entries = [];
    foreach ($mappings as $code => $to) {
        if ($to === [$code]) {
            continue;
        }
        if (count($to) > 3) {
            fwrite(STDERR, sprintf("U+%04X maps to more than three characters\n", $code));
            exit(1);
        }
        $entries[] = sprintf('{0x%04X, {%s}}', $code, implode(', ', array_map(fn ($c) => sprintf('0x%04X', $c), $to)));
    }

    return "static const CaseMapping {$name}[] = {\n".rows($entries, 4)."};\n";
}

/**
 * A property's code points as ranges, in order and with neighbours joined
 *
 * @param  list<array{int, int}>  $ranges
 */
function range_table(string $name, array $ranges): string
{
    sort($ranges);
    $joined = [];
    foreach ($ranges as [$first, $last]) {
        if ($joined !== [] && $joined[count($joined) - 1][1] + 1 >= $first) {
            $joined[count($joined) - 1][1] = max($last, $joined[count($joined) - 1][1]);
        } else {
            $joined[] = [$first, $last];
        }
    }
    $entries = array_map(fn ($r) => sprintf('{0x%04X, 0x%04X}', $r[0], $r[1]), $joined);

    return "static const CodeRange {$name}[] = {\n".rows($entries, 6)."};\n";
}

/**
 * @param  list<string>  $entries
 */
function rows(array $entries, int $across): string
{
    $rows = '';
    foreach (array_chunk($entries, $across) as $chunk) {
        $rows .= '    '.implode(', ', $chunk).",\n";
    }

    return $rows;
}

$header = '/* Generated by tools/unicode.php from the Unicode Character Database '.VERSION.", never edited by\n"
    ." * hand; vm/unicode.c is what reads it */\n\n"
    .'#define UNICODE_VERSION "'.VERSION."\"\n\n"
    .mapping_table('upper_mappings', $upper)."\n"
    .mapping_table('lower_mappings', $lower)."\n"
    .mapping_table('fold_mappings', $fold)."\n"
    .range_table('cased_ranges', $properties['Cased'])."\n"
    .range_table('case_ignorable_ranges', $properties['Case_Ignorable'])."\n"
    .range_table('zero_width_ranges', $zero_width)."\n"
    .range_table('wide_ranges', $wide);
file_put_contents("{$root}/vm/unicode_tables.h", $header);
echo 'vm/unicode_tables.h: Unicode '.VERSION."\n";
