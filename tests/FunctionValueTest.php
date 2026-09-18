<?php

namespace GazLang\Tests;

use GazLang\GazLangError;

class FunctionValueTest extends GazLangTestCase
{
    private const ADD = 'fn add($a, $b) { return $a + $b; } ';

    public function test_a_bare_name_is_a_function_value_that_can_be_called()
    {
        $this->assertEquals("3\n3\n", $this->executeCode(self::ADD.'$f = add; echo $f(1, 2); echo (add)(1, 2);'));
    }

    public function test_builtins_are_values_too()
    {
        $this->assertEquals("3\nABC\n", $this->executeCode('$f = len; echo $f("abc"); $u = upper; echo $u("abc");'));
    }

    public function test_calls_on_elements_and_on_call_results()
    {
        $this->assertEquals("7\n3\n3\n", $this->executeCode(
            self::ADD.'fn pick() { return add; } $h = {"add" => add, "len" => len};'
            .' echo $h["add"](3, 4); echo $h["len"]("abc"); echo pick()(1, 2);'
        ));
    }

    public function test_functions_can_be_passed_stored_globally_defaulted_and_iterated()
    {
        $this->assertEquals("6\n9\n3\n6\n3\nabc\n", $this->executeCode(<<<'CODE'
            fn apply($f, $x) { return $f($x); }
            fn triple($x) { return $x * 3; }
            fn with_default($x, $f = triple) { return $f($x); }
            @op = triple;
            fn use_global($x) { return @op($x); }
            echo apply(triple, 2);
            echo use_global(3);
            echo with_default([1, 2, 3], len);
            echo with_default(2);
            foreach ([len, lower] as $f) { echo $f("ABC"); }
            CODE));
    }

    public function test_calls_work_inside_interpolation()
    {
        $this->assertEquals("got 3!\n", $this->executeCode('$f = len; echo "got {$f("abc")}!";'));
    }

    public function test_printing_type_and_equality()
    {
        $this->assertEquals("function add\n[function add, function len]\nfunction\ntrue\nfalse\ntrue\nfalse\nxfunction add\ntruthy\n", $this->executeCode(
            self::ADD.'echo add; echo [add, len]; echo type_of(add); echo add == add; echo add == len;'
            .' echo in_array(add, [len, add]); echo in_array(add, [len]); echo "x" .. add; if (add) { echo "truthy"; }'
        ));
    }

    public function test_a_reference_in_an_included_file_can_name_a_function_declared_by_the_includer()
    {
        $expected = ["8\n10\n", 0];
        $this->assertSame($expected, $this->runProgram('tests/fixtures/function_values/main.gaz'));
        $this->assertSame($expected, $this->runProgram('tests/fixtures/function_values/main.gaz', [], true));
    }

    /**
     * @dataProvider parseErrors
     */
    public function test_parse_errors(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->createParser($code)->parse();
    }

    public static function parseErrors(): array
    {
        return [
            'undeclared bare name' => ['$f = missing;', 'Undefined function or constant: missing on line 1'],
            'undeclared name in an array' => ["\$h = [\n  len,\n  nope,\n];", 'Undefined function or constant: nope on line 3'],
            'assigning to a call' => ['$f = len; $f(1) = 2;', 'Can only use = on a variable, or an element or field of one on line 1'],
            'incrementing a call' => ['$f = len; $f(1)++;', 'Can only use ++ on a variable, or an element or field of one on line 1'],
            'call by name still checked' => ['$f = len; len(1, 2);', 'Function len expects 1 arguments, 2 given on line 1'],
            'the first mistake in source order is reported' => ["len(1, 2);\nnope();", 'Function len expects 1 arguments, 2 given on line 1'],
            'even when a reference comes later' => ["zap();\n\$f = nope;", 'Undefined function: zap on line 1'],
        ];
    }

    /**
     * @dataProvider runtimeErrors
     */
    public function test_runtime_errors_are_catchable_with_their_line(string $code, string $message)
    {
        $this->assertEquals("{$message}\n", $this->executeCode(
            self::ADD."try {\n{$code}\n} catch (\$e) { echo \$e.message .. \" on line \" .. \$e.line; }"
        ));
    }

    public static function runtimeErrors(): array
    {
        return [
            'calling an int' => ['$g = 5; $g(1);', 'Cannot call int on line 2'],
            'calling null' => ['null(1);', 'Cannot call null on line 2'],
            'calling a string' => ['"add"(1, 2);', 'Cannot call string on line 2'],
            'too few arguments through a value' => ['$f = add; $f(1);', 'Function add expects 2 arguments, 1 given on line 2'],
            'too many arguments to a builtin value' => ['$l = len; $l("a", "b");', 'Function len expects 1 arguments, 2 given on line 2'],
            'optional arguments through a value' => ['$s = slice; $s();', 'Function slice expects 2 to 3 arguments, 0 given on line 2'],
            'multi-line call reports the line of its opening paren' => ["\$f = add;\n\$f(\n1\n);", 'Function add expects 2 arguments, 1 given on line 3'],
            'arithmetic' => ['echo add + 1;', 'Cannot use + on function on line 2'],
            'ordering' => ['echo add < len;', 'Cannot use < on function on line 2'],
            'negation' => ['echo -add;', 'Cannot use - on function on line 2'],
            'as a map key' => ['echo {add => 1};', 'Keys must be int or string, got function on line 2'],
            'as an index' => ['$a = [1]; echo $a[add];', 'Keys must be int or string, got function on line 2'],
            'indexing it' => ['echo add[0];', 'Cannot use [] on function on line 2'],
            'foreach over it' => ['foreach (add as $x) {}', 'foreach expects a list or map, got function on line 2'],
            'builtin argument type' => ['echo len(add);', 'len() expects list or map or string, got function on line 2'],
            'callee is evaluated before the arguments are checked' => ['$five = 5; $five(error("first"));', 'first on line 2'],
        ];
    }

    public function test_error_as_a_value_keeps_its_message_uncaught()
    {
        $this->expectExceptionMessage('boom');
        try {
            $this->executeCode('$e = error; $e("boom");');
        } catch (GazLangError $e) {
            $this->assertFalse($e->show_location);
            $this->assertSame(1, $e->line_number);

            throw $e;
        }
    }

    public function test_runaway_recursion_through_a_value_is_a_gazlang_error()
    {
        // Through the CLI, which restarts itself without pcov (see FunctionTest)
        $code = 'fn inf() { $f = inf; return $f(); } echo inf();';
        foreach (['', '--interpreter'] as $backend) {
            $command = sprintf('echo %s | %s %s %s 2>&1', escapeshellarg($code), escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__.'/../bin/gazlang-php'), $backend);
            exec($command, $output, $exit_code);

            $this->assertSame('Error: Maximum call depth of 10000 exceeded calling inf on line 1', $output[0], "with {$backend}");
            $this->assertSame(1, $exit_code);
            $output = [];
        }
    }

    public function test_code_gen()
    {
        $this->assertEquals(
            "PUSH_FN len\nSTORE 0\nLOAD 0\nPOP\nLOAD 0\nPUSH \"abc\"\nCALL_VALUE 1\nPRINT\nPUSH_FN add\nPUSH 1\nPUSH 2\nCALL_VALUE 2\nPOP\nfn add 2 2\nLOAD 0\nLOAD 1\nADD\nRET\nPUSH null\nRET",
            $this->generateCode(self::ADD.'$f = len; echo $f("abc"); (add)(1, 2);')
        );
    }

    public function test_a_literal_with_functions_is_built_at_runtime_not_folded()
    {
        $this->assertStringContainsString("NEW_ARRAY\nPUSH_FN len\nARRAY_PUSH", $this->generateCode('$a = [len];'));
    }
}
