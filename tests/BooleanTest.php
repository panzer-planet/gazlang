<?php

namespace GazLang\Tests;

use GazLang\Lexer\Token;

class BooleanTest extends GazLangTestCase
{
    public function test_lexes_true_and_false_keywords()
    {
        $lexer = $this->createLexer('true false $true');
        $this->assertEquals(Token::TRUE, $lexer->get_next_token()->type);
        $this->assertEquals(Token::FALSE, $lexer->get_next_token()->type);
        $this->assertEquals(Token::VAR_IDENTIFIER, $lexer->get_next_token()->type);
    }

    public function test_echo_prints_true_and_false()
    {
        $this->assertEquals("true\nfalse\ntrue\nfalse\n", $this->executeCode(
            'echo true; echo false; echo 1 < 2; echo !true;'
        ));
    }

    public function test_booleans_act_as_one_and_zero_in_arithmetic_and_comparisons()
    {
        $this->assertEquals("2\n0\n-1\ntrue\ntrue\nfalse\n", $this->executeCode(
            'echo true + 1; echo false * 5; echo -true; echo true == 1; echo false == 0; echo true == 2;'
        ));
    }

    public function test_concatenation_uses_echo_spelling()
    {
        $this->assertEquals("xtrue\nfalse!\n", $this->executeCode('echo "x" .. true; echo (1 > 2) .. "!";'));
    }

    public function test_truthiness_stays_c_like()
    {
        $this->assertEquals("five\nzero is false\nyes\n", $this->executeCode(
            'if (5) { echo "five"; } if (0) { echo "zero is true"; } else { echo "zero is false"; }'
            .' $ok = true; while ($ok) { echo "yes"; $ok = false; }'
        ));
    }

    public function test_strings_are_true_unless_empty()
    {
        $this->assertEquals("empty is false\nfalse\ntrue\ntrue\nfalse\n", $this->executeCode(
            'if ("") { echo "empty is true"; } else { echo "empty is false"; }'
            .' echo !"abc"; echo !""; echo "a" && "0"; echo "" || 0;'
        ));
    }

    public function test_strings_compare_byte_by_byte()
    {
        $this->assertEquals("false\ntrue\nfalse\ntrue\ntrue\n", $this->executeCode(
            'echo "1" == "01"; echo "10" < "9"; echo "10" == "1e1"; echo "abc" == "abc"; echo "5" == 5;'
        ));
    }

    /**
     * @dataProvider overflows
     */
    public function test_overflow_cases(string $code)
    {
        $this->expectExceptionMessage('Integer overflow');
        $this->executeCode($code);
    }

    public static function overflows(): array
    {
        return [
            'multiply' => ['echo 4611686018427387904 * 2;'],
            'subtract' => ['echo -9223372036854775807 - 2;'],
            'negate the minimum' => ['$min = -9223372036854775807 - 1; echo -$min;'],
            'divide the minimum by -1' => ['$min = -9223372036854775807 - 1; echo $min / -1;'],
        ];
    }

    public function test_mixed_string_and_number_comparisons()
    {
        // Strings holding a number literal compare as numbers (booleans act as 1/0); any other string never equals a number
        $this->assertEquals("true\ntrue\ntrue\nfalse\nfalse\ntrue\nfalse\ntrue\ntrue\ntrue\nfalse\n", $this->executeCode(
            'echo "5" == 5; echo 7 == "007"; echo true == "1"; echo true == "abc"; echo false == "";'
            .' echo "1e0" == 1; echo " 5" == 5; echo "abc" != 1; echo "-3" < 2; echo "2.50" == 2.5; echo "1." == 1;'
        ));
    }

    public function test_ordering_a_non_integer_string_against_an_int_is_an_error()
    {
        $this->expectExceptionMessage('Cannot use < on string and int on line 1');
        $this->executeCode('echo "abc" < 1;');
    }

    public function test_integer_overflow_is_an_error()
    {
        // PHP would silently turn the result into a float, which GazLang has no type for
        $this->expectExceptionMessage('Integer overflow on line 1');
        $this->executeCode('echo 9223372036854775807 + 1;');
    }

    public function test_code_gen_pushes_boolean_literals()
    {
        $this->assertEquals("PUSH true\nPUSH false\nEQUALS\nPRINT", $this->generateCode('echo true == false;'));
    }
}
