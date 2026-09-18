<?php

namespace GazLang\Tests;

use GazLang\Lexer\Token;

class FunctionTest extends GazLangTestCase
{
    public function test_lexes_function_syntax_and_global_variables()
    {
        $lexer = $this->createLexer('fn add($a, @b) return null');
        $expected = [
            Token::FN, Token::IDENTIFIER, Token::LEFT_PAREN, Token::VAR_IDENTIFIER, Token::COMMA,
            Token::GLOBAL_VAR_IDENTIFIER, Token::RIGHT_PAREN, Token::RETURN, Token::NULL, Token::EOF,
        ];

        foreach ($expected as $type) {
            $this->assertEquals($type, $lexer->get_next_token()->type);
        }
    }

    public function test_invalid_global_variable_name()
    {
        $this->expectExceptionMessage('Invalid variable name: @1 on line 1');
        $this->createLexer('@1')->get_next_token();
    }

    public function test_call_with_arguments_and_return_value()
    {
        $this->assertEquals("5\n3.5\n", $this->executeCode(
            'fn add($a, $b) { return $a + $b; } echo add(2, 3); echo add(0.5, 3);'
        ));
    }

    public function test_function_can_be_called_before_it_is_declared()
    {
        $this->assertEquals("42\n", $this->executeCode('echo answer(); fn answer() { return 42; }'));
    }

    public function test_recursion_and_mutual_recursion()
    {
        $this->assertEquals("120\ntrue\nfalse\n", $this->executeCode(<<<'CODE'
            fn fact($n) { if ($n <= 1) { return 1; } return $n * fact($n - 1); }
            fn is_even($n) { if ($n == 0) { return true; } return is_odd($n - 1); }
            fn is_odd($n) { if ($n == 0) { return false; } return is_even($n - 1); }
            echo fact(5);
            echo is_even(10);
            echo is_even(7);
            CODE));
    }

    public function test_arguments_are_evaluated_in_the_callers_scope()
    {
        $this->assertEquals("7\n", $this->executeCode(
            'fn inc($x) { return $x + 1; } $x = 5; echo inc($x + 1);'
        ));
    }

    public function test_locals_do_not_leak_between_caller_and_function()
    {
        $this->assertEquals("99\n7\n", $this->executeCode(
            'fn clobber() { $i = 99; return $i; } $i = 7; echo clobber(); echo $i;'
        ));
    }

    public function test_function_cannot_read_top_level_locals()
    {
        $this->expectExceptionMessage('Undefined variable: $secret');
        $this->executeCode('$secret = 1; fn peek() { return $secret; } peek();');
    }

    public function test_globals_are_shared_and_separate_from_locals()
    {
        $this->assertEquals("3\nlocal\n", $this->executeCode(<<<'CODE'
            @count = 0;
            $count = "local";
            fn bump() { @count = @count + 1; }
            bump(); bump(); bump();
            echo @count;
            echo $count;
            CODE));
    }

    public function test_missing_return_value_is_null()
    {
        $this->assertEquals("null\nnull\n", $this->executeCode(
            'fn nothing() { } fn bare() { return; } echo nothing(); echo bare();'
        ));
    }

    public function test_return_from_inside_a_loop()
    {
        $this->assertEquals("3\n", $this->executeCode(
            'fn first_over($limit) { for ($i = 0; true; $i = $i + 1) { if ($i > $limit) { return $i; } } } echo first_over(2);'
        ));
    }

    public function test_function_names_can_start_with_an_underscore()
    {
        $this->assertEquals("1\n", $this->executeCode('fn _helper() { return 1; } echo _helper();'));
    }

    public function test_deep_recursion_does_not_grow_memory_quadratically()
    {
        // Every return used to create an exception with a full stack trace: depth 400 took ~300MB.
        // The peak is the process's, so start it afresh: other tests run deep programs in-process
        memory_reset_peak_usage();
        $this->executeCode('fn down($n) { if ($n == 0) { return 0; } return down($n - 1); } down(400);');
        $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage());
    }

    public function test_runaway_recursion_is_a_gazlang_error()
    {
        // The interpreter, through the CLI, which restarts itself without pcov: pcov makes every
        // PHP call use the C stack, which segfaults long before the call depth limit is reached
        $command = sprintf(
            'echo %s | %s %s --interpreter 2>&1',
            escapeshellarg('fn inf() { return inf(); } echo inf();'),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__.'/../bin/gazlang')
        );
        exec($command, $output, $exit_code);

        $this->assertSame('Error: Maximum call depth of 10000 exceeded calling inf on line 1', $output[0]);
        // The trace under it is capped: 10 innermost calls, a line saying what was left out, 10 outermost
        $this->assertSame(22, count($output));
        $this->assertSame('  inf on line 1', $output[1]);
        $this->assertSame('  ... 9981 more', $output[11]);
        $this->assertSame(1, $exit_code);
    }

    /**
     * @dataProvider defaultParameterErrors
     */
    public function test_default_parameter_parse_errors(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->createParser($code)->parse();
    }

    public static function defaultParameterErrors(): array
    {
        return [
            'required after optional' => ['fn f($a = 1, $b) { }', "Required parameter \$b can't follow a parameter with a default on line 1"],
            'too few arguments' => ['fn f($a, $b = 1) { } f();', 'Function f expects 1 to 2 arguments, 0 given on line 1'],
            'too many arguments' => ['fn f($a, $b = 1) { } f(1, 2, 3);', 'Function f expects 1 to 2 arguments, 3 given on line 1'],
            'all optional, too many' => ['fn f($a = 1) { } f(1, 2);', 'Function f expects 0 to 1 arguments, 2 given on line 1'],
            'undefined function in a default' => ['fn f($a = missing()) { }', 'Undefined function: missing on line 1'],
            'default without a value' => ['fn f($a = ) { }', "Unexpected ')' on line 1"],
        ];
    }

    public function test_code_gen_evaluates_defaults_only_for_missing_arguments()
    {
        $this->assertStringContainsString(
            "fn f 1 2\nARGC\nPUSH 1\nGT\nNOT\nJZ PASSED_0\nLOAD 0\nSTORE 1\nLABEL PASSED_0\nLOAD 1\nRET",
            $this->generateCode('echo f(2); fn f($a, $b = $a) { return $b; }')
        );
    }

    public function test_code_gen_checks_each_default_against_its_position()
    {
        $this->assertStringContainsString(
            "fn f 1 3\n"
            ."ARGC\nPUSH 1\nGT\nNOT\nJZ PASSED_0\nPUSH 2\nSTORE 1\nLABEL PASSED_0\n"
            ."ARGC\nPUSH 2\nGT\nNOT\nJZ PASSED_1\nLOAD 1\nSTORE 2\nLABEL PASSED_1",
            $this->generateCode('f(1); fn f($a, $b = 2, $c = $b) { }')
        );
    }

    public function test_undefined_function_is_a_parse_error_even_if_never_called()
    {
        $this->expectExceptionMessage('Undefined function: missing');
        $this->createParser('if (false) { missing(); }')->parse();
    }

    public function test_wrong_argument_count_is_a_parse_error()
    {
        $this->expectExceptionMessage('Function add expects 2 arguments, 1 given');
        $this->createParser('echo add(1); fn add($a, $b) { return $a + $b; }')->parse();
    }

    public function test_duplicate_function_is_a_parse_error()
    {
        $this->expectExceptionMessage('Function f is already declared');
        $this->createParser('fn f() { } fn f() { }')->parse();
    }

    public function test_duplicate_parameter_is_a_parse_error()
    {
        $this->expectExceptionMessage('Duplicate parameter $a in function f');
        // Reported before the default is read, so a bad default doesn't hide it
        $this->createParser('fn f($a, $a) { }')->parse();
    }

    public function test_global_parameter_is_a_parse_error()
    {
        $this->expectExceptionMessage("Expected a \$variable but found '@a'");
        $this->createParser('fn f(@a) { }')->parse();
    }

    public function test_nested_function_is_a_parse_error()
    {
        $this->expectExceptionMessage('Functions can only be declared at the top level');
        $this->createParser('fn outer() { fn inner() { } }')->parse();
    }

    public function test_the_old_function_keyword_says_to_use_fn()
    {
        $this->expectExceptionMessage('Declare functions with fn, not function on line 1');
        $this->createParser('function add($a, $b) { return $a + $b; }')->parse();
    }

    public function test_return_outside_a_function_is_a_parse_error()
    {
        $this->expectExceptionMessage('Cannot use return outside of a function');
        $this->createParser('while (true) { return 1; }')->parse();
    }

    public function test_break_in_a_function_cannot_reach_the_callers_loop()
    {
        $this->expectExceptionMessage('Cannot use break outside of a loop');
        $this->createParser('fn stop() { break; } while (true) { stop(); }')->parse();
    }

    public function test_code_gen_for_functions_and_globals()
    {
        $this->assertEquals(
            "PUSH 1\nPUSH 2\nCALL add 2\nPRINT\n"
            ."fn add 2 2\nLOAD_GLOBAL 0\nPUSH 1\nADD\nSTORE_GLOBAL 0\nLOAD_GLOBAL 0\nPOP\n"
            ."LOAD 0\nLOAD 1\nADD\nRET\nPUSH null\nRET",
            $this->generateCode('echo add(1, 2); fn add($a, $b) { @calls = @calls + 1; return $a + $b; }')
        );
    }

    public function test_code_gen_gives_each_function_its_own_frame()
    {
        $this->assertEquals(
            "PUSH 1\nSTORE 0\nLOAD 0\nPOP\nCALL f 0\nPOP\n"
            ."fn f 0 0\nPUSH 2\nSTORE 0\nLOAD 0\nPOP\nPUSH null\nRET\nPUSH null\nRET",
            $this->generateCode('$x = 1; f(); fn f() { $y = 2; return; }')
        );
    }
}
