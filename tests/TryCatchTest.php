<?php

namespace GazLang\Tests;

use GazLang\GazLangError;

class TryCatchTest extends GazLangTestCase
{
    public function test_running_out_of_call_depth_can_be_caught()
    {
        // The interpreter, through the CLI, which runs without pcov: in-process, interpreter
        // recursion this deep can segfault (VMTest covers the VM in-process)
        exec(sprintf(
            'echo %s | %s %s --interpreter',
            escapeshellarg('fn recurse() { return recurse(); } try { recurse(); } catch ($e) { echo $e.message; } echo "still running";'),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::ROOT.'/bin/gazlang')
        ), $output, $exit_code);

        $this->assertSame(['Maximum call depth of 10000 exceeded calling recurse', 'still running'], $output);
        $this->assertSame(0, $exit_code);
    }

    public function test_error_in_a_catch_block_is_not_caught_by_the_same_try()
    {
        $this->expectExceptionMessage('Cannot use * on string on line 3');
        $this->executeCode("try {\n    error(\"first\");\n} catch (\$e) { \$x = \"a\" * 2; }");
    }

    public function test_uncaught_error_still_prints_its_message_exactly()
    {
        try {
            $this->executeCode("\n\nerror(\"Syntax error in input on line 7\");");
            $this->fail('Expected an error');
        } catch (GazLangError $e) {
            $this->assertSame('Syntax error in input on line 7', $e->getMessage());
            // The location is still recorded, for catch
            $this->assertSame(3, $e->line_number);
        }
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
            'try without catch' => ['try { } echo 1;', "Expected 'catch' or 'finally' but found 'echo'"],
            'catch without a variable' => ['try { } catch { }', "Expected '(' but found '{'"],
            'catch with a non-variable' => ['try { } catch (1) { }', "Expected a \$variable but found '1'"],
            'catch without try' => ['catch ($e) { }', "Unexpected 'catch'"],
            'untyped catch before another' => ['try { } catch ($e) { } catch (Error $e) { }', 'A catch without a class catches every error, so it must be the last'],
            'unknown catch class' => ["try { }\ncatch (Nope \$e) { }", 'Undefined class: Nope on line 2'],
            'function as a catch class' => ['fn f() {} try { } catch (f $e) { }', 'f is a function, not a class on line 1'],
            'declaring Error' => ['class Error {}', 'Error is a builtin class on line 1'],
            'constructing Error without a message' => ['echo Error();', 'Class Error expects 1 arguments, 0 given on line 1'],
            'syntax errors are not caught' => ['try { echo ; } catch ($e) { }', "Unexpected ';'"],
        ];
    }

    private const ERRORS = <<<'CODE'
        class NotFound extends Error {
            #key;
            fn _($key) { ##_("Not found: {$key}"); #key = $key; }
        }
        class Missing extends NotFound {}
        fn find($key) { error(Missing($key)); }

        CODE;

    public function test_typed_catches_match_the_class_and_its_subclasses_first_match_wins()
    {
        $this->assertEquals("not found: a (Not found: a) on line 6\nerror: Division by zero\nanything: 5\n", $this->executeCode(self::ERRORS.<<<'CODE'
            try { find("a"); } catch (NotFound $e) { echo "not found: {$e.key} ({$e}) on line {$e.line}"; } catch (Error $e) { echo "not run"; }
            try { $x = 1 / 0; } catch (NotFound $e) { echo "not run"; } catch (Error $e) { echo "error: {$e.message}"; }
            try { error(5); } catch (Error $e) { echo "not run"; } catch ($e) { echo "anything: {$e}"; }
            CODE));
    }

    public function test_an_error_no_clause_matches_carries_on_unchanged_through_finally()
    {
        $this->assertEquals("finally\nouter: Division by zero on line 9\n", $this->executeCode(self::ERRORS.<<<'CODE'
            try {
                try {
                    $x = 1 / 0;
                } catch (NotFound $e) {
                    echo "not run";
                } finally {
                    echo "finally";
                }
            } catch (Error $e) {
                echo "outer: {$e.message} on line {$e.line}";
            }
            CODE));
    }

    public function test_runtime_errors_and_error_with_a_string_are_error_objects()
    {
        $this->assertEquals("object true [bad] bad on line 1\nundefined true\n", $this->executeCode(<<<'CODE'
            try { error("bad"); } catch ($e) { echo type_of($e) .. " " .. is_a($e, Error) .. " " .. [$e] .. " {$e.message} on line {$e.line}"; }
            try { echo $nope; } catch (Error $e) { echo "undefined " .. ($e.message == "Undefined variable: \$nope"); }
            CODE));
    }

    public function test_thrown_values_are_caught_as_they_are()
    {
        $this->assertEquals("[1, \"a\"]\n{\"k\" => 1}\nnull\nP {}\ntrue\n", $this->executeCode(<<<'CODE'
            class P {}
            $p = P();
            foreach ([[1, "a"], {"k" => 1}, null, $p] as $value) {
                try { error($value); } catch ($e) { echo $e; }
            }
            try { error($p); } catch ($e) { echo $e == $p; }
            CODE));
    }

    public function test_an_error_object_records_where_it_is_first_thrown()
    {
        $this->assertEquals("made, not thrown: null\nthrown on line 10\nrethrown keeps line 10\n", $this->executeCode(self::ERRORS.<<<'CODE'
            $e = NotFound("k");
            echo "made, not thrown: " .. ($e.line ?? "null");
            try {
                error($e);
            } catch (NotFound $caught) {
                echo "thrown on line {$caught.line}";
                try { error($caught); } catch ($again) { echo "rethrown keeps line {$again.line}"; }
            }
            CODE));
    }

    public function test_error_classes_without_a_catch()
    {
        $this->assertEquals("Not found: x\nclass Error\n", $this->executeCode(self::ERRORS.'echo NotFound("x"); echo Error;'));
    }

    /**
     * @dataProvider uncaughtValues
     */
    public function test_uncaught_values_print_as_echo_would(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode(self::ERRORS.$code);
    }

    public static function uncaughtValues(): array
    {
        return [
            'an error object' => ['find("zz");', 'Not found: zz'],
            'an int' => ['error(5);', '5'],
            'a list' => ['error([1, "a"]);', '[1, "a"]'],
            'an object without to_string' => ['class P {} error(P());', 'P {}'],
            'an Error whose message was never set' => ['class Oops extends Error { fn _() {} } error(Oops());', 'Property message of Oops is not set at <builtin>:6'],
        ];
    }

    public function test_code_gen_for_try_catch()
    {
        $this->assertStringStartsWith(
            "TRY CATCH_0\nPUSH_STR \"x\"\nCALL_BUILTIN error 1\nPOP\nEND_TRY\nJMP ENDTRY_0\n"
            ."LABEL CATCH_0\nCATCH_VALUE\nSTORE 0\nLOAD 0\nGET_PROPERTY message\nPRINT\nLABEL ENDTRY_0\nHALT\nLABEL NEW_Error\n",
            $this->generateCode('try { error("x"); } catch ($e) { echo $e.message; }')
        );
    }

    public function test_code_gen_for_typed_catches()
    {
        $this->assertStringStartsWith(
            "TRY CATCH_0\nEND_TRY\nJMP ENDTRY_0\nLABEL CATCH_0\n"
            ."CATCH_MATCH P NEXTCATCH_0_0\nSTORE 0\nJMP ENDTRY_0\nLABEL NEXTCATCH_0_0\n"
            ."CATCH_MATCH Error NEXTCATCH_0_1\nSTORE 0\nJMP ENDTRY_0\nLABEL NEXTCATCH_0_1\nRETHROW\nLABEL ENDTRY_0\nHALT\n",
            $this->generateCode('class P {} try { } catch (P $e) { } catch (Error $e) { }')
        );
    }

    public function test_code_gen_returns_from_inside_try_with_ret()
    {
        // RET drops the returning frame's handlers, so no END_TRY is emitted before it
        $this->assertStringContainsString(
            "LABEL FN_f\nTRY CATCH_0\nPUSH 1\nRET\nEND_TRY\nJMP ENDTRY_0\nLABEL CATCH_0\nCATCH_VALUE\nSTORE 0\nLABEL ENDTRY_0",
            $this->generateCode('f(); fn f() { try { return 1; } catch ($e) { } }')
        );
    }

    public function test_code_gen_leaves_try_when_breaking_out_of_a_foreach()
    {
        $code = $this->generateCode('foreach ([1] as $v) { try { continue; } catch ($e) { } }');

        // The lowered foreach's continue runs its step, after leaving the try
        $this->assertMatchesRegularExpression('/TRY CATCH_\d+\nEND_TRY\nJMP CONTINUE_\d+/', $code);
    }

    public function test_code_gen_leaves_each_try_when_breaking_out_of_a_loop()
    {
        $code = $this->generateCode('try { while (true) { try { try { break; } catch ($a) { } } catch ($b) { } } } catch ($c) { }');

        // Two tries inside the loop are left by the break; the one around the loop is not
        $this->assertStringContainsString("TRY CATCH_3\nEND_TRY\nEND_TRY\nJMP ENDWHILE_1", $code);
    }
}
