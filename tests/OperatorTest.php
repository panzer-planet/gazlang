<?php

namespace GazLang\Tests;

use Exception;
use GazLang\Lexer\Token;

class OperatorTest extends GazLangTestCase
{
    public function test_lexes_new_operators()
    {
        $lexer = $this->createLexer('< <= > >= != ! && || == =');
        $expected = [
            Token::LESS_THAN, Token::LESS_EQUALS, Token::GREATER_THAN, Token::GREATER_EQUALS,
            Token::NOT_EQUALS, Token::NOT, Token::AND, Token::OR, Token::EQUALS, Token::ASSIGN, Token::EOF,
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

    public function test_relational_and_equality()
    {
        $this->assertEquals("true\nfalse\ntrue\ntrue\nfalse\ntrue\n", $this->executeCode(
            'echo 1 < 2; echo 2 < 2; echo 2 <= 2; echo 3 > 2; echo 2 >= 3; echo 1 != 2;'
        ));
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
