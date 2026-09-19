<?php

namespace GazLang\Tests;

use JsonException;

/**
 * Checks lib/json.gaz against PHP's json_decode on every file in tests/json
 *
 * y_*.json must decode, and PHP must decode GazLang's re-encoding of it to exactly
 * the value PHP decodes from the original, int and float types and {} versus [] included. n_*.json
 * must stop with an "Invalid JSON" error. PHP must agree with each file's prefix.
 */
class JsonTest extends GazLangTestCase
{
    public static function documents(): array
    {
        $documents = [];
        foreach (glob(self::ROOT.'/tests/json/*.json') as $path) {
            $documents[basename($path)] = ['tests/json/'.basename($path)];
        }

        return $documents;
    }

    /**
     * @dataProvider documents
     */
    public function test_document_matches_php(string $file)
    {
        $text = file_get_contents(self::ROOT."/{$file}");
        $valid = str_starts_with(basename($file), 'y_');
        [$output, $exit_code] = $this->runProgram('tests/fixtures/json_roundtrip.gaz', [$file]);

        if (! $valid) {
            $this->assertNull($this->phpDecode($text), "PHP accepts {$file}, so it should not be an n_ file");
            $this->assertStringStartsWith('Error: Invalid JSON: ', $output);
            $this->assertSame(1, $exit_code);

            return;
        }

        $expected = $this->phpDecode($text);
        $this->assertNotNull($expected, "PHP rejects {$file}, so it should not be a y_ file");
        $this->assertSame(0, $exit_code, $output);
        $actual = $this->phpDecode(rtrim($output, "\n"));
        $this->assertNotNull($actual, "GazLang output is not valid JSON: {$output}");
        $this->assertSame($expected[0], $actual[0]);
        // Objects as stdClass, so an empty object stays distinct from an empty array
        $this->assertSame(json_encode(json_decode($text), JSON_PRESERVE_ZERO_FRACTION), json_encode(json_decode($output), JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @dataProvider errorMessages
     */
    public function test_error_messages_say_what_and_where(string $json, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode('include "'.self::ROOT.'/lib/json.gaz"; json_decode('.self::quote($json).');');
    }

    public static function errorMessages(): array
    {
        return [
            'trailing comma' => ['[1,]', 'Invalid JSON: unexpected "]" at position 3'],
            'missing colon' => ['{"a" 1}', 'Invalid JSON: expected : but found "1" at position 5'],
            'missing comma' => ['[1 2]', 'Invalid JSON: expected , or ] but found "2" at position 3'],
            'unquoted key' => ['{a: 1}', 'Invalid JSON: expected a string key but found "a" at position 1'],
            'leading zero' => ['01', 'Invalid JSON: unexpected "1" after the value at position 1'],
            'end of input' => ['[', 'Invalid JSON: unexpected end of input at position 1'],
            'empty' => ['', 'Invalid JSON: unexpected end of input at position 0'],
            'control character' => ["\"a\tb\"", 'Invalid JSON: unescaped control character in string at position 2'],
            'invalid escape' => ['"\\q"', 'Invalid JSON: invalid escape "\\\\q" at position 1'],
            'short unicode escape' => ['"\\'.'u12"', 'Invalid JSON: expected four hex digits after \\u at position 5'],
            'lone surrogate' => ['"\\'.'ud800"', 'Invalid JSON: unpaired UTF-16 surrogate at position 7'],
            'exponent' => ['1e+', 'Invalid JSON: expected a digit in the exponent at position 3'],
            'fraction' => ['1.x', 'Invalid JSON: expected a digit after the decimal point at position 2'],
            'too deep' => [str_repeat('[', 512), 'Invalid JSON: nesting deeper than 511 levels at position 511'],
        ];
    }

    /**
     * PHP's decoding, wrapped in an array so a decoded null is distinguishable from failure
     *
     * @return array{0: mixed}|null
     */
    private function phpDecode(string $json): ?array
    {
        try {
            return [json_decode($json, true, 512, JSON_THROW_ON_ERROR)];
        } catch (JsonException) {
            return null;
        }
    }
}
