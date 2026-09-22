<?php

namespace GazLang\Tests;

class LambdaTest extends GazLangTestCase
{
    public function test_lexes_the_arrow()
    {
        $this->assertSame(
            ['VAR_IDENTIFIER', 'ARROW', 'VAR_IDENTIFIER', 'DECREMENT', 'GREATER_THAN', 'ARROW', 'MINUS', 'MINUS', 'GREATER_THAN'],
            array_column($this->lex('$x -> $x --> ->- - >'), 0)
        );
    }

    public function test_every_head_form_and_both_body_kinds()
    {
        $this->assertEquals("42\n3\n15\n42\nbig\nnull\n", $this->executeCode(<<<'CODE'
            $double = $x -> $x * 2;
            echo $double(21);
            $add = ($a, $b = 10) -> $a + $b;
            echo $add(1, 2);
            echo $add(5);
            $k = () -> 42;
            echo $k();
            $block = $x -> { if ($x > 1) { return "big"; } };
            echo $block(2);
            echo $block(0);
            CODE));
    }

    public function test_bodies_extend_as_far_right_as_possible_and_nest()
    {
        $this->assertEquals("true\n7\n6\n5\n", $this->executeCode(<<<'CODE'
            $f = $x -> $x * 2 == 4;
            echo $f(2);
            $curry = $x -> $y -> $x + $y;
            echo $curry(3)(4);
            $three = $x -> $y -> $z -> $x + $y + $z;
            echo $three(1)(2)(3);
            echo ($x -> $x .. "")(5);
            CODE));
    }

    public function test_lambdas_are_values_passed_stored_and_returned()
    {
        $this->assertEquals("15\n6\n6\n[2, 4]\n7\n", $this->executeCode(<<<'CODE'
            fn apply($f, $v) { return $f($v); }
            echo apply($x -> $x * 3, 5);
            fn adder($k) { return $x -> $x + $k; }
            $add5 = adder(5);
            echo $add5(1);
            fn with_default($cb = $x -> $x * 2) { return $cb(3); }
            echo with_default();
            $ops = {"double" => $x -> $x * 2};
            $out = [];
            foreach ([1, 2] as $n) { $out[] = $ops["double"]($n); }
            echo $out;
            $c = false;
            echo ($c ? $x -> 1 : $y -> 7)(0);
            CODE));
    }

    public function test_capture_is_by_value_at_creation()
    {
        $this->assertEquals("1\n100 2\n11\n[1, 2]\n[5, 1] [5]\n2\n", $this->executeCode(<<<'CODE'
            $n = 1;
            $f = () -> $n;
            $n = 2;
            echo $f();
            $g = () -> { $n = 100; return $n; };
            echo $g() .. " " .. $n;
            $x = 10;
            $d = ($a, $b = $x) -> $a + $b;
            $x = 0;
            echo $d(1);
            $a = [1];
            $push = () -> { $a[] = 2; return $a; };
            echo $push();
            $b = [5];
            $more = () -> { $b[] = 1; $b[0] += 0; return $b; };
            echo $more() .. " " .. $b;
            $total = 0;
            $sum = $items -> { foreach ($items as $i) { $total += $i; } return $total; };
            echo $sum([1, 1]);
            CODE));
    }

    public function test_a_function_named_like_a_lambda_frame_keeps_its_own_variable_names()
    {
        $this->assertEquals("Undefined variable: \$b\nUndefined key: \"k\"\n", $this->executeCode(
            'fn lambda_0($a) { return $b; } fn f() { $z = {}; $z["k"]["m"] = 1; } $l = () -> 1;'
            .' try { lambda_0(1); } catch ($e) { echo $e.message; } try { f(); } catch ($e) { echo $e.message; }'
        ));
    }

    public function test_globals_are_read_live_and_are_the_shared_state()
    {
        $this->assertEquals("2\n", $this->executeCode('@count = 0; $inc = () -> { @count++; }; $inc(); $inc(); echo @count;'));
    }

    public function test_a_variable_assigned_after_the_lambda_in_a_loop_is_captured_next_time_round()
    {
        $this->assertEquals("none\n1\n", $this->executeCode(
            '$fs = []; for ($i = 0; $i < 2; $i++) { $fs[] = () -> $y ?? "none"; $y = 1; } echo $fs[0](); echo $fs[1]();'
        ));
    }

    public function test_an_uncaptured_variable_is_undefined_inside()
    {
        $this->assertEquals("Undefined variable: \$missing on line 2\n", $this->executeCode(
            "\$h = () -> {\n return \$missing; };\ntry { \$h(); } catch (\$e) { echo \$e.message .. \" on line \" .. \$e.line; }"
        ));
    }

    public function test_a_closure_keeps_its_captured_variables_between_calls()
    {
        $this->assertEquals('3 1
5
0
[1, 2] []
11 12 21
3
', $this->executeCode(<<<'CODE'
            fn counter() { $n = 0; return () -> ++$n; }
            $c = counter(); $d = counter(); $c(); $c();
            echo $c() .. " " .. $d();
            // A closure is one value: another variable holding it shares its variables
            $e = $c; $e();
            echo $c();
            $n = 0;
            $bump = () -> { $n += 1; };
            $bump();
            echo $n;
            $list = [];
            $add = $x -> { $list[] = $x; return $list; };
            $add(1);
            echo $add(2) .. " " .. $list;
            // Each closure made in a loop has its own copy
            $fs = [];
            foreach ([1, 2] as $v) { $k = $v * 10; $fs[] = () -> ++$k; }
            echo $fs[0]() .. " " .. $fs[0]() .. " " .. $fs[1]();
            $outer = () -> { $m = 1; return () -> ++$m; };
            $inner = $outer(); $inner();
            echo $inner();
            CODE));
    }

    public function test_recursive_calls_share_the_closures_variables()
    {
        $this->assertEquals('23416728348467685
data 1
', $this->executeCode(<<<'CODE'
            $memo = {};
            $fib = $n -> $memo[$n] ??= ($n < 2 ? $n : $fib($n - 1) + $fib($n - 2));
            echo $fib(80);
            @loads = 0;
            fn load() { @loads++; return "data"; }
            $get = () -> { $cache ??= load(); return $cache; };
            $get(); $get();
            echo $get() .. " " .. @loads;
            CODE));
    }

    public function test_a_plain_assignment_makes_a_variable_local_to_each_call()
    {
        $this->assertEquals('10 0
Undefined variable: $n
[3, 2, 1] 0
', $this->executeCode(<<<'CODE'
            // $result shares its name with an outer variable, but each recursive call has its own
            $result = 0;
            $sum = $n -> { if ($n == 0) { return 0; } $result = $n; $rest = $sum($n - 1); return $result + $rest; };
            echo $sum(4) .. " " .. $result;
            $n = 5;
            $bad = () -> { $n = $n + 1; return $n; };
            try { $bad(); } catch ($err) { echo $err.message; }
            $i = 0;
            $down = $n -> { $out = []; for ($i = $n; $i > 0; $i--) { $out[] = $i; } return $out; };
            echo $down(3) .. " " .. $i;
            CODE));
    }

    public function test_a_closure_assigned_to_a_variable_can_call_itself()
    {
        $this->assertEquals("6\n6\n5\n", $this->executeCode(<<<'CODE'
            $fact = $n -> $n < 2 ? 1 : $n * $fact($n - 1);
            echo $fact(3);
            // Its own $fact stays the closure when the outer variable changes
            $keep = $fact;
            $fact = 5;
            echo $keep(3);
            echo $fact;
            CODE));
    }

    public function test_only_a_plain_assignment_of_the_lambda_itself_binds_its_name()
    {
        $this->assertEquals("Undefined variable: \$g\nCannot call int\n", $this->executeCode(<<<'CODE'
            $h = [$n -> $g($n)];
            try { $h[0](1); } catch ($e) { echo $e.message; }
            $g = 5;
            $k = true ? $n -> $g($n) : null;
            try { $k(1); } catch ($e) { echo $e.message; }
            CODE));
    }

    public function test_identity_printing_and_type()
    {
        $this->assertEquals("true\nfalse\nfalse\ntrue\nfunction -> on line 1\n[function -> on line 1]\nx function -> on line 1\nfunction\n", $this->executeCode(
            '$f = $x -> $x; $g = $x -> $x; echo $f == $f; echo $f == $g; echo ($x -> $x) == ($x -> $x); echo in_array($f, [$g, $f]);'
            .' echo $f; echo [$f]; echo "x " .. $f; echo type_of($f);'
        ));
    }

    public function test_errors_inside_a_body_unwind_to_the_callers_try_with_their_line()
    {
        $this->assertEquals("Cannot use + on string on line 2\nafter\nok\n", $this->executeCode(
            "\$f = () ->\n \"a\" + 1;\ntry { \$f(); } catch (\$e) { echo \$e.message .. \" on line \" .. \$e.line; }\necho \"after\";\n"
            .'$g = () -> { try { return "ok"; } catch ($e) { return "caught"; } }; echo $g();'
        ));
    }

    /**
     * @dataProvider runtimeErrors
     */
    public function test_runtime_errors(string $code, string $message)
    {
        $this->assertEquals("{$message}\n", $this->executeCode("try { {$code} } catch (\$e) { echo \$e.message; }"));
    }

    public static function runtimeErrors(): array
    {
        return [
            'too many arguments' => ['$f = $x -> $x; $f(1, 2);', 'Function -> on line 1 expects 1 arguments, 2 given'],
            'too few with defaults' => ['$f = ($a, $b = 1) -> $a; $f();', 'Function -> on line 1 expects 1 to 2 arguments, 0 given'],
            'arithmetic on a closure' => ['echo ($x -> $x) + 1;', 'Cannot use + on function'],
        ];
    }

    /**
     * @dataProvider parseErrors
     */
    public function test_parse_errors(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->parse($code);
    }

    public static function parseErrors(): array
    {
        return [
            'lambda is at the expression level' => ['1 + $x -> 2;', "Expected ';' but found '->' on line 1"],
            'global parameter' => ['@x -> 1;', "Expected ';' but found '->' on line 1"],
            'literal parameter' => ['(1) -> 2;', 'Lambda parameters must be $variables or [$variable, ...] patterns on line 1'],
            'global in the list' => ['($a, @b) -> 1;', 'Lambda parameters must be $variables or [$variable, ...] patterns on line 1'],
            'no body' => ['$x -> ;', "Unexpected ';' on line 1"],
            'return in an expression body' => ['$x -> return 1;', "Unexpected 'return' on line 1"],
            'break in a block body outside a loop' => ['while (true) { $f = () -> { break; }; }', 'Cannot use break outside of a loop on line 1'],
            'empty parentheses without an arrow' => ['();', "Unexpected ')' on line 1"],
            'empty parentheses in an operand' => ['echo () + 1;', "Unexpected ')' on line 1"],
            'parenthesised head is at the expression level too' => ['echo 1 + ($x) -> 2;', "Expected ';' but found '->' on line 1"],
            'parenthesised head after ??' => ['$f = null; echo $f ?? () -> 7;', "Unexpected ')' on line 1"],
            'parenthesised head after !' => ['echo !() -> 1;', "Unexpected ')' on line 1"],
            'doubly parenthesised parameter' => ['(($a)) -> 1;', 'Lambda parameters must be $variables or [$variable, ...] patterns on line 1'],
            'parenthesised parameter in a list' => ['($a, ($b)) -> 1;', 'Lambda parameters must be $variables or [$variable, ...] patterns on line 1'],
            'indexed parameter' => ['($a[0] = 1) -> 1;', 'Lambda parameters must be $variables or [$variable, ...] patterns on line 1'],
            'duplicate parameter reports its own line' => ['($a,
 $a
) -> 1;', 'Duplicate parameter $a in lambda on line 2'],
            'comma in grouping that is not a head fails at once' => ['echo 1 + (1, 2 ~);', "Unexpected ',' on line 1"],
            'a comma in grouping' => ["(1,\n 2);", "Expected ')' but found ',' on line 1"],
            'duplicate parameter' => ['($a, $a) -> 1;', 'Duplicate parameter $a in lambda on line 1'],
            'required after default' => ['($a = 1, $b) -> 1;', "Required parameter \$b can't follow a parameter with a default on line 1"],
            'syntax errors come before later lexer errors' => ['(1 +, $b ~);', "Unexpected ',' on line 1"],
            'ternary else still cannot assign' => ['$a = true; $a ? 1 : $b = 2;', 'Can only use = on a variable, or an element or field of one on line 1'],
        ];
    }

    public function test_return_is_allowed_in_a_block_body_at_top_level_and_the_enclosing_function_keeps_its_own()
    {
        $this->assertEquals("1\n2\n", $this->executeCode(
            'echo (() -> { return 1; })(); fn f() { $g = () -> { return 2; }; return $g(); } echo f();'
        ));
    }

    public function test_calls_inside_interpolation()
    {
        $this->assertEquals("1 and 2\n", $this->executeCode('$f = $x -> $x; echo "{$f(1)} and {$f($x -> $x)(2)}";'));
    }

    public function test_runaway_recursion_through_a_global_closure_is_a_gazlang_error()
    {
        $code = '@f = () -> @f(); @f();';
        [$output, $exit_code] = self::cli([], $code);

        $this->assertSame('Error: Maximum call depth of 10000 exceeded calling -> on line 1 on line 1', $output[0]);
        $this->assertSame(1, $exit_code);
    }

    public function test_code_gen()
    {
        // A program with only a lambda still ends its top level in HALT. The captured $n takes
        // slot 0 of the top level (allocated by the capture map) and is the closure's variable 0
        $this->assertEquals(
            "MAKE_CLOSURE 0\nSTORE 1\nLOAD 1\nPOP\nLOAD 1\nPUSH 1\nCALL_VALUE 1\nPRINT\nlambda 0 1 1\nLOAD 0\nLOAD_CAPTURED 0\nADD\nRET",
            $this->generateCode('$f = $x -> $x + $n; echo $f(1);')
        );
        // Updating a captured variable writes the closure's; a plain = makes a frame local
        $this->assertStringEndsWith(
            "lambda 0 0 0\nLOAD_CAPTURED 0\nINC\nSTORE_CAPTURED 0\nLOAD_CAPTURED 0\nPOP\nPUSH 1\nSTORE 0\nLOAD 0\nPOP\nPUSH null\nRET",
            $this->generateCode('$n = 0; $m = 0; $f = () -> { ++$n; $m = 1; };')
        );
        // A block body returns null when it falls off the end
        $this->assertStringEndsWith("lambda 0 0 0\nPUSH 1\nPOP\nPUSH null\nRET", $this->generateCode('$f = () -> { 1; };'));
    }

    public function test_show_location_of_a_closure_names_the_file()
    {
        [$output] = $this->runProgram('tests/fixtures/closure_name.gaz');
        $this->assertSame("function -> at tests/fixtures/closure_name.gaz:2\n", $output);
    }

    public function test_error_kind_is_gazlang_error_for_a_bad_lambda_head()
    {
        $this->expectException(ProgramError::class);
        $this->parse('(1) -> 2;');
    }
}
