<?php

namespace GazLang\Tests;

use GazLang\Interpreter\Interpreter;

class StdlibTest extends GazLangTestCase
{
    public function test_slice_strings_and_arrays()
    {
        $this->assertEquals("ell\nlo\n\n[2, 3]\n[\"b\" => 2]\n", $this->executeCode(
            'echo slice("hello", 1, 3); echo slice("hello", -2, 5); echo slice("hi", 5, 1);'
            .' echo slice([1, 2, 3], 1, 2); echo slice(["a" => 1, "b" => 2], 1, 1);'
        ));
    }

    public function test_lower()
    {
        $this->assertEquals("function\n", $this->executeCode('echo lower("FuncTION");'));
    }

    public function test_to_int()
    {
        $this->assertEquals("42\n-7\n8\n5\n", $this->executeCode(
            'echo to_int("42"); echo to_int("-7"); echo to_int("007") + 1; echo to_int(5);'
        ));
    }

    /**
     * @dataProvider invalidInts
     */
    public function test_to_int_rejects_anything_else(string $argument, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode("echo to_int({$argument});");
    }

    public static function invalidInts(): array
    {
        return [
            'letters' => ['"12a"', 'to_int() cannot convert "12a"'],
            'empty' => ['""', 'to_int() cannot convert ""'],
            'spaces' => ['" 1"', 'to_int() cannot convert " 1"'],
            'overflow' => ['"99999999999999999999"', 'to_int() cannot convert "99999999999999999999"'],
            'bool' => ['true', 'to_int() cannot convert bool'],
        ];
    }

    public function test_to_string_matches_echo()
    {
        $this->assertEquals("42|true|null|[1, \"a\"]\n", $this->executeCode(
            'echo to_string(42) + "|" + to_string(true) + "|" + to_string(null) + "|" + to_string([1, "a"]);'
        ));
    }

    public function test_in_array_is_strict()
    {
        $this->assertEquals("true\nfalse\nfalse\ntrue\n", $this->executeCode(
            'echo in_array(1, [1, 2]); echo in_array("1", [1, 2]); echo in_array(true, [1]); echo in_array([1], [[1]]);'
        ));
    }

    public function test_has_key_and_keys()
    {
        $this->assertEquals("true\nfalse\n[\"a\", 5]\n", $this->executeCode(
            '$m = ["a" => null, 5 => 1]; echo has_key($m, "a"); echo has_key($m, "b"); echo keys($m);'
        ));
    }

    public function test_type_of()
    {
        $this->assertEquals("int string bool null array\n", $this->executeCode(
            'echo type_of(1) + " " + type_of("") + " " + type_of(false) + " " + type_of(null) + " " + type_of([]);'
        ));
    }

    public function test_error_stops_the_program()
    {
        $this->expectExceptionMessage('Undefined variable: $x in 3');
        $this->executeCode('error("Undefined variable: $x in " + 3); echo "unreachable";');
    }

    public function test_read_file()
    {
        $this->assertEquals("hello\n\n", $this->executeCode('echo read_file("'.__DIR__.'/fixtures/read_me.txt");'));
    }

    public function test_read_file_missing()
    {
        $this->expectExceptionMessage('Cannot read file: nope.txt');
        $this->executeCode('read_file("nope.txt");');
    }

    public function test_args()
    {
        $interpreter = new Interpreter($this->createParser('echo args(); echo len(args());'), ['a', '-b']);

        ob_start();
        $interpreter->interpret();
        $this->assertEquals("[\"a\", \"-b\"]\n2\n", ob_get_clean());
    }

    public function test_cli_passes_remaining_arguments_to_the_program()
    {
        exec(sprintf(
            'echo %s | %s %s -- -x two',
            escapeshellarg('echo args();'),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__.'/../bin/gazlang')
        ), $output, $exit_code);

        $this->assertSame(['["-x", "two"]'], $output);
        $this->assertSame(0, $exit_code);
    }

    /**
     * @dataProvider wrongArgumentTypes
     */
    public function test_builtins_check_argument_types(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode($code);
    }

    public static function wrongArgumentTypes(): array
    {
        return [
            'slice target' => ['slice(5, 0, 1);', 'slice() expects string or array, got int'],
            'slice start' => ['slice("abc", "0", 1);', 'slice() expects int, got string'],
            'lower' => ['lower(1);', 'lower() expects string, got int'],
            'in_array haystack' => ['in_array(1, "1");', 'in_array() expects array, got string'],
            'has_key array' => ['has_key("a", 1);', 'has_key() expects array, got string'],
            'has_key key' => ['has_key([], null);', 'Array keys must be int or string, got null'],
            'keys' => ['keys(null);', 'keys() expects array, got null'],
            'read_file' => ['read_file(1);', 'read_file() expects string, got int'],
        ];
    }

    public function test_code_gen_for_builtins()
    {
        $this->assertEquals(
            "PUSH_STR \"abc\"\nPUSH 0\nPUSH 1\nCALL_BUILTIN slice 3\nPRINT\nCALL_BUILTIN args 0\nPOP",
            $this->generateCode('echo slice("abc", 0, 1); args();')
        );
    }
}
