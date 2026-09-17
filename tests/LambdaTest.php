<?php

namespace GazLang\Tests;

use GazLang\GazLangError;
use GazLang\Lexer\Token;

class LambdaTest extends GazLangTestCase
{
    public function test_lexes_the_arrow()
    {
        $this->assertSame(
            [Token::VAR_IDENTIFIER, Token::ARROW, Token::VAR_IDENTIFIER, Token::DECREMENT, Token::GREATER_THAN, Token::ARROW, Token::MINUS, Token::MINUS, Token::GREATER_THAN, Token::EOF],
            array_map(fn ($token) => $token->type, $this->tokens('$x -> $x --> ->- - >'))
        );
    }

    private function tokens(string $code): array
    {
        $lexer = $this->createLexer($code);
        $tokens = [];
        do {
            $tokens[] = $token = $lexer->get_next_token();
        } while ($token->type !== Token::EOF);

        return $tokens;
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
            function apply($f, $v) { return $f($v); }
            echo apply($x -> $x * 3, 5);
            function adder($k) { return $x -> $x + $k; }
            $add5 = adder(5);
            echo $add5(1);
            function with_default($cb = $x -> $x * 2) { return $cb(3); }
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
            'function lambda_0($a) { return $b; } function f() { $z = {}; $z["k"]["m"] = 1; } $l = () -> 1;'
            .' try { lambda_0(1); } catch ($e) { echo $e["message"]; } try { f(); } catch ($e) { echo $e["message"]; }'
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
            "\$h = () -> {\n return \$missing; };\ntry { \$h(); } catch (\$e) { echo \$e[\"message\"] .. \" on line \" .. \$e[\"line\"]; }"
        ));
    }

    public function test_a_closure_cannot_see_the_variable_it_is_assigned_to()
    {
        // Capture happens before the assignment: $fact doesn't exist yet, or holds its old value
        $this->assertEquals("Undefined variable: \$fact\nCannot call int\n", $this->executeCode(<<<'CODE'
            $fact = $n -> $n < 2 ? 1 : $n * $fact($n - 1);
            try { echo $fact(3); } catch ($e) { echo $e["message"]; }
            $f = 5;
            $f = $n -> $f($n);
            try { echo $f(3); } catch ($e) { echo $e["message"]; }
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
            "\$f = () ->\n \"a\" + 1;\ntry { \$f(); } catch (\$e) { echo \$e[\"message\"] .. \" on line \" .. \$e[\"line\"]; }\necho \"after\";\n"
            .'$g = () -> { try { return "ok"; } catch ($e) { return "caught"; } }; echo $g();'
        ));
    }

    /**
     * @dataProvider runtimeErrors
     */
    public function test_runtime_errors(string $code, string $message)
    {
        $this->assertEquals("{$message}\n", $this->executeCode("try { {$code} } catch (\$e) { echo \$e[\"message\"]; }"));
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
        $this->createParser($code)->parse();
    }

    public static function parseErrors(): array
    {
        return [
            'lambda is at the expression level' => ['1 + $x -> 2;', "Expected ';' but found '->' on line 1"],
            'global parameter' => ['@x -> 1;', "Expected ';' but found '->' on line 1"],
            'literal parameter' => ['(1) -> 2;', 'Lambda parameters must be $variables on line 1'],
            'global in the list' => ['($a, @b) -> 1;', 'Lambda parameters must be $variables on line 1'],
            'no body' => ['$x -> ;', "Unexpected ';' on line 1"],
            'return in an expression body' => ['$x -> return 1;', "Unexpected 'return' on line 1"],
            'break in a block body outside a loop' => ['while (true) { $f = () -> { break; }; }', 'Cannot use break outside of a loop on line 1'],
            'empty parentheses without an arrow' => ['();', "Unexpected ')' on line 1"],
            'empty parentheses in an operand' => ['echo () + 1;', "Unexpected ')' on line 1"],
            'parenthesised head is at the expression level too' => ['echo 1 + ($x) -> 2;', "Expected ';' but found '->' on line 1"],
            'parenthesised head after ??' => ['$f = null; echo $f ?? () -> 7;', "Unexpected ')' on line 1"],
            'parenthesised head after !' => ['echo !() -> 1;', "Unexpected ')' on line 1"],
            'doubly parenthesised parameter' => ['(($a)) -> 1;', 'Lambda parameters must be $variables on line 1'],
            'parenthesised parameter in a list' => ['($a, ($b)) -> 1;', 'Lambda parameters must be $variables on line 1'],
            'indexed parameter' => ['($a[0] = 1) -> 1;', 'Lambda parameters must be $variables on line 1'],
            'duplicate parameter reports its own line' => ['($a,
 $a
) -> 1;', 'Duplicate parameter $a in lambda on line 2'],
            'comma in grouping that is not a head fails at once' => ['echo 1 + (1, 2 ~);', "Unexpected ',' on line 1"],
            'a comma in grouping' => ["(1,\n 2);", "Expected ')' but found ',' on line 1"],
            'duplicate parameter' => ['($a, $a) -> 1;', 'Duplicate parameter $a in lambda on line 1'],
            'required after default' => ['($a = 1, $b) -> 1;', "Required parameter \$b can't follow a parameter with a default on line 1"],
            'syntax errors come before later lexer errors' => ['(1 +, $b ~);', "Unexpected ',' on line 1"],
            'ternary else still cannot assign' => ['$a = true; $a ? 1 : $b = 2;', 'Can only use = on a variable or an element of one on line 1'],
            'block body inside interpolation (the first } ends the interpolation)' => ['$f = 1; echo "{$f -> { return 1; }}";', 'Unexpected string "}" on line 1'],
        ];
    }

    public function test_return_is_allowed_in_a_block_body_at_top_level_and_the_enclosing_function_keeps_its_own()
    {
        $this->assertEquals("1\n2\n", $this->executeCode(
            'echo (() -> { return 1; })(); function f() { $g = () -> { return 2; }; return $g(); } echo f();'
        ));
    }

    public function test_calls_inside_interpolation()
    {
        $this->assertEquals("1 and 2\n", $this->executeCode('$f = $x -> $x; echo "{$f(1)} and {$f($x -> $x)(2)}";'));
    }

    public function test_runaway_recursion_through_a_global_closure_is_a_gazlang_error()
    {
        $code = '@f = () -> @f(); @f();';
        foreach (['', '--interpreter'] as $backend) {
            $command = sprintf('echo %s | %s %s %s', escapeshellarg($code), escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__.'/../bin/gazlang'), $backend);
            exec($command, $output, $exit_code);

            $this->assertSame(['Error: Maximum call depth of 10000 exceeded calling -> on line 1 on line 1'], $output, "with {$backend}");
            $this->assertSame(1, $exit_code);
            $output = [];
        }
    }

    public function test_code_gen()
    {
        // A program with only a lambda still ends its top level in HALT. The captured $n takes
        // slot 0 of the top level (allocated by the capture map) and slot 1 of the lambda, after $x
        $this->assertEquals(
            "MAKE_CLOSURE 0\nSTORE 1\nLOAD 1\nPOP\nLOAD 1\nPUSH 1\nCALL_VALUE 1\nPRINT\nHALT\nLABEL LAMBDA_0\nLOAD 0\nLOAD 1\nADD\nRET",
            $this->generateCode('$f = $x -> $x + $n; echo $f(1);')
        );
        // A block body returns null when it falls off the end
        $this->assertStringEndsWith("LABEL LAMBDA_0\nPUSH 1\nPOP\nPUSH null\nRET", $this->generateCode('$f = () -> { 1; };'));
    }

    public function test_show_location_of_a_closure_names_the_file()
    {
        [$output] = $this->runProgram('tests/fixtures/closure_name.gaz');
        $this->assertSame("function -> at tests/fixtures/closure_name.gaz:2\n", $output);
        [$vm_output] = $this->runProgram('tests/fixtures/closure_name.gaz', [], true);
        $this->assertSame($output, $vm_output);
    }

    public function test_error_class_is_gazlang_error_for_a_bad_lambda_head()
    {
        $this->expectException(GazLangError::class);
        $this->createParser('(1) -> 2;')->parse();
    }
}
