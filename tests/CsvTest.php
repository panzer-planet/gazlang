<?php

namespace GazLang\Tests;

use GazLang\Lexer\Lexer;

/**
 * lib/csv.gaz against PHP's fgetcsv, and examples/csv_report.gaz end to end
 *
 * tests/csv/y_*.csv must parse to the same rows as fgetcsv (with no escape character,
 * as RFC 4180 has none, and without the [null] rows fgetcsv gives blank lines);
 * n_*.csv must be rejected with a "CSV error". Everything runs on both backends.
 */
class CsvTest extends GazLangTestCase
{
    public static function documents(): array
    {
        $documents = [];
        foreach (glob(self::ROOT.'/tests/csv/*.csv') as $path) {
            $documents[basename($path)] = ['tests/csv/'.basename($path)];
        }
        $documents['sales.csv'] = ['examples/data/sales.csv'];

        return $documents;
    }

    /**
     * @dataProvider documents
     */
    public function test_document_matches_php(string $file)
    {
        [$output, $exit_code] = $this->runProgram('tests/fixtures/csv_dump.gaz', [$file]);

        if (str_starts_with(basename($file), 'n_')) {
            $this->assertStringStartsWith('Error: CSV error on line ', $output);
            $this->assertSame(1, $exit_code);

            return;
        }

        $this->assertSame(0, $exit_code, $output);
        $rows = array_map(fn ($line) => json_decode($line, true), array_filter(explode("\n", $output), fn ($line) => $line !== ''));
        $this->assertSame($this->phpRows($file), $rows, $file);
    }

    public function test_format_number_matches_php_number_format()
    {
        // Tricky halves, carries, negatives and zero, plus many ordinary amounts
        $numbers = [0, 0.005, 1.005, 2.675, 1.255, 0.285, 5.045, 5.055, -1.005, 999.995, 999.999, -0.004, 1234567.891, -9876.5];
        mt_srand(42);
        for ($i = 0; $i < 300; $i++) {
            $numbers[] = round(mt_rand(-10000000, 10000000) / 1000, 3);
        }

        $code = 'include "'.self::ROOT.'/lib/format.gaz"; foreach ('
            .'['.implode(', ', array_map(fn ($n) => is_float($n) ? Lexer::format_float($n) : (string) $n, $numbers)).']'
            .' as $n) { echo format_number($n) .. " " .. format_number($n, 1) .. " " .. format_number($n, 0); }';
        $expected = implode('', array_map(fn ($n) => number_format($n, 2).' '.number_format($n, 1).' '.number_format($n, 0)."\n", $numbers));

        $this->assertSame($expected, $this->executeCode($code));
    }

    public function test_report_on_the_sample_data()
    {
        $expected = <<<'TEXT'
            REGION  ROWS      TOTAL   AVERAGE
            South      3   4,560.49  1,520.16
            East       3   3,436.09  1,145.36
            North      4   1,524.00    381.00
            West       3     899.95    299.98
            ---------------------------------
            All       13  10,420.53    801.58

            TEXT;

        $this->assertSame([$expected, 0], $this->runProgram('examples/csv_report.gaz'));
    }

    public function test_report_groups_by_any_column_and_keeps_line_breaks_out_of_the_table()
    {
        [$output] = $this->runProgram('examples/csv_report.gaz', ['examples/data/sales.csv', 'product', 'amount']);

        $this->assertStringContainsString("\nService plan (12 months)     1     480.00    480.00\n", $output);
        // Equal totals keep the order they first appeared in
        $this->assertMatchesRegularExpression('/The "Deluxe" Gizmo .*\nGizmo /', $output);
    }

    /**
     * @dataProvider reportErrors
     */
    public function test_report_errors(array $args, string $message)
    {
        $usage = "Usage: bin/gazlang -f examples/csv_report.gaz -- FILE GROUP_COLUMN AMOUNT_COLUMN\n";

        $this->assertSame([$usage."Error: {$message}\n", 1], $this->runProgram('examples/csv_report.gaz', $args));
    }

    public static function reportErrors(): array
    {
        return [
            'wrong argument count' => [['a.csv'], 'expected 3 arguments, got 1'],
            'missing file' => [['nope.csv', 'a', 'b'], 'Cannot read file: nope.csv'],
            'unknown column' => [['examples/data/sales.csv', 'regin', 'amount'], 'examples/data/sales.csv has no column "regin" (columns: date, region, product, amount)'],
            'invalid CSV' => [['tests/csv/n_unclosed_quote.csv', 'a', 'b'], 'CSV error on line 2: quoted field is never closed'],
            'ragged row' => [['tests/csv/y_ragged_rows.csv', 'a', 'b'], 'CSV error in row 3: 1 field, but the header has 2'],
            'not a number' => [['tests/csv/y_simple.csv', 'age', 'name'], 'row 2: name "Ada" is not a number'],
        ];
    }

    /**
     * The rows PHP's fgetcsv reads from a file, without the [null] rows of blank lines
     */
    private function phpRows(string $file): array
    {
        $handle = fopen(self::ROOT."/{$file}", 'r');
        $rows = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($row !== [null]) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        return $rows;
    }
}
