<?php

namespace GazLang\Tests;

use Exception;
use GazLang\Lexer\Token;

class OperatorTest extends GazLangTestCase
{
    public function test_lexes_new_operators()
    {
        $lexer = $this->createLexer('< <= > >= != ! && || == = === !==');
        $expected = [
            Token::LESS_THAN, Token::LESS_EQUALS, Token::GREATER_THAN, Token::GREATER_EQUALS,
            Token::NOT_EQUALS, Token::NOT, Token::AND, Token::OR, Token::EQUALS, Token::ASSIGN,
            Token::STRICT_EQUALS, Token::STRICT_NOT_EQUALS, Token::EOF,
        ];

        foreach ($expected as $type) {
            $this->assertEquals($type, $lexer->get_next_token()->type);
        }
    }

    public function test_single_ampersand_is_invalid()
    {
        $this->expectException(Exception::class);
        $this->createLexer('&')->get_next_token();
    }

    /**
     * @dataProvider floatErrors
     */
    public function test_float_errors(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode($code);
    }

    public static function floatErrors(): array
    {
        return [
            'float overflow' => ['echo 1e308 * 10;', 'Float overflow on line 1'],
            'float overflow by division' => ['echo 1e308 / 1e-10;', 'Float overflow on line 1'],
            'modulo with a float' => ['echo 5.5 % 2;', 'Cannot use % on float on line 1'],
            'division by float zero' => ['echo 1 / 0.0;', 'Division by zero on line 1'],
            'exact division that overflows' => ['echo (-9223372036854775807 - 1) / -1;', 'Integer overflow on line 1'],
            'float array key' => ['echo [1.5 => 1];', 'Array keys must be int or string, got float on line 1'],
            'float string position' => ['echo "abc"[1.0];', 'String positions must be int, got float on line 1'],
            'ordering a non-number string' => ['echo "abc" < 1.5;', 'Cannot use < on string and float on line 1'],
            'arithmetic on a string' => ['echo "1.5" + 1.5 * "2";', 'Cannot use * on string on line 1'],
            'to_int out of range' => ['to_int(1e19);', 'to_int() cannot convert 1.0E+19 on line 1'],
            'to_float of a non-number' => ['to_float("1.5x");', 'to_float() cannot convert "1.5x" on line 1'],
            'to_float of a bool' => ['to_float(true);', 'to_float() cannot convert bool on line 1'],
            'intdiv by zero' => ['intdiv(7, 0);', 'Division by zero on line 1'],
            'intdiv with a float' => ['intdiv(7, 2.0);', 'intdiv() expects int, got float on line 1'],
            'intdiv overflow' => ['intdiv(-9223372036854775807 - 1, -1);', 'Integer overflow on line 1'],
            'abs overflow' => ['abs(-9223372036854775807 - 1);', 'Integer overflow on line 1'],
            'round of a string' => ['round("1.5");', 'round() expects int or float, got string on line 1'],
        ];
    }

    public function test_code_gen_pushes_floats_exactly()
    {
        $this->assertEquals("PUSH 0.1\nPUSH 1.0E+25\nMUL\nPRINT", $this->generateCode('echo 0.1 * 1e25;'));
    }

    public function test_modulo()
    {
        // The sign follows the left operand; % binds like * and /
        $this->assertEquals("1\n-1\n1\n0\n8\n0\n", $this->executeCode(
            'echo 7 % 3; echo -7 % 3; echo 7 % -3; echo 6 % 3; echo 2 + 7 % 4 * 2; echo (-9223372036854775807 - 1) % -1;'
        ));
    }

    public function test_modulo_by_zero()
    {
        $this->expectExceptionMessage('Modulo by zero on line 1');
        $this->executeCode('echo 1 % 0;');
    }

    public function test_modulo_on_a_string()
    {
        $this->expectExceptionMessage('Cannot use % on string');
        $this->executeCode('echo "7" % 2;');
    }

    public function test_code_gen_for_modulo()
    {
        $this->assertEquals("PUSH 7\nPUSH 3\nMOD\nPRINT", $this->generateCode('echo 7 % 3;'));
    }

    public function test_relational_and_equality()
    {
        $this->assertEquals("true\nfalse\ntrue\ntrue\nfalse\ntrue\n", $this->executeCode(
            'echo 1 < 2; echo 2 < 2; echo 2 <= 2; echo 3 > 2; echo 2 >= 3; echo 1 != 2;'
        ));
    }

    public function test_strict_equality_compares_type_and_value()
    {
        $this->assertEquals("true\nfalse\ntrue\nfalse\ntrue\nfalse\ntrue\nfalse\n", $this->executeCode(
            'echo "5" == 5; echo "5" === 5; echo true == 1; echo true === 1;'
            .' echo 5 === 5; echo "1" === "01"; echo "5" !== 5; echo (1 < 2) !== true;'
        ));
    }

    public function test_strict_equality_binds_like_equality()
    {
        // (1 + 1) === 2, and (1 === 1) == true
        $this->assertEquals("true\ntrue\n", $this->executeCode('echo 1 + 1 === 2; echo 1 === 1 == true;'));
    }

    public function test_code_gen_for_strict_equality()
    {
        $this->assertEquals(
            "PUSH_STR \"5\"\nPUSH 5\nSTRICT_EQUALS\nPUSH 1\nSTRICT_NOT_EQUALS\nPRINT",
            $this->generateCode('echo "5" === 5 !== 1;')
        );
    }

    public function test_chained_equality_is_left_associative()
    {
        // (1 == 2) == 0 is true; the old parser read it as 1 == (2 == 0), which is false
        $this->assertEquals("true\n", $this->executeCode('echo 1 == 2 == 0;'));
    }

    public function test_relational_binds_tighter_than_equality()
    {
        // (2 < 1) == 0, not 2 < (1 == 0)
        $this->assertEquals("true\n", $this->executeCode('echo 2 < 1 == 0;'));
    }

    public function test_arithmetic_binds_tighter_than_relational()
    {
        $this->assertEquals("true\n", $this->executeCode('echo 1 + 2 < 2 * 2;'));
    }

    public function test_and_binds_tighter_than_or()
    {
        // 1 || (0 && 0) is true; (1 || 0) && 0 would be false
        $this->assertEquals("true\n", $this->executeCode('echo 1 || 0 && 0;'));
    }

    public function test_logical_operators_short_circuit()
    {
        $this->assertEquals("0\n0\n", $this->executeCode(
            '$x = 0; 0 && ($x = 1); echo $x; 1 || ($x = 2); echo $x;'
        ));
    }

    public function test_arithmetic_on_strings_throws()
    {
        $this->expectExceptionMessage('Cannot use * on string');
        $this->executeCode('echo "a" * 2;');
    }

    public function test_unary_operators()
    {
        $this->assertEquals("true\nfalse\ntrue\n2\n-6\n", $this->executeCode(
            'echo !0; echo !5; echo !!5; echo -3 + 5; echo -(1 + 2) * 2;'
        ));
    }

    public function test_not_binds_tighter_than_equality()
    {
        // (!1) == 0
        $this->assertEquals("true\n", $this->executeCode('echo !1 == 0;'));
    }

    public function test_assignment_is_right_associative_and_lowest()
    {
        $this->assertEquals("6\ntrue\n", $this->executeCode(
            '$a = $b = 3; echo $a + $b; $c = $a < 4 && $b > 2; echo $c;'
        ));
    }

    public function test_assignment_to_non_variable_throws()
    {
        $this->expectException(Exception::class);
        $this->createParser('1 + $x = 3;')->parse();
    }

    public function test_comparison_in_if_condition()
    {
        $this->assertEquals("small\n", $this->executeCode(
            '$n = 3; if ($n > 10 || $n < 0) { echo "out"; } else if ($n <= 5) { echo "small"; } else { echo "big"; }'
        ));
    }

    public function test_code_gen_for_comparison_and_unary()
    {
        $this->assertEquals(
            "PUSH 1\nPUSH 2\nLT\nNOT\nPOP",
            $this->generateCode('!(1 < 2);')
        );
        $this->assertEquals("PUSH 3\nNEG\nPOP", $this->generateCode('-3;'));
    }

    public function test_code_gen_for_logical_and()
    {
        $this->assertEquals(
            "PUSH 1\nJZ AND_FALSE_0\nPUSH 0\nJZ AND_FALSE_0\nPUSH true\nJMP AND_END_0\n"
            ."LABEL AND_FALSE_0\nPUSH false\nLABEL AND_END_0\nPOP",
            $this->generateCode('1 && 0;')
        );
    }

    public function test_code_gen_for_logical_or()
    {
        $this->assertEquals(
            "PUSH 1\nNOT\nJZ OR_TRUE_0\nPUSH 0\nNOT\nJZ OR_TRUE_0\nPUSH false\nJMP OR_END_0\n"
            ."LABEL OR_TRUE_0\nPUSH true\nLABEL OR_END_0\nPOP",
            $this->generateCode('1 || 0;')
        );
    }
}
