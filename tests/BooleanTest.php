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
        $this->assertEquals("xtrue\nfalse!\n", $this->executeCode('echo "x" + true; echo (1 > 2) + "!";'));
    }

    public function test_truthiness_stays_c_like()
    {
        $this->assertEquals("five\nzero is false\nyes\n", $this->executeCode(
            'if (5) { echo "five"; } if (0) { echo "zero is true"; } else { echo "zero is false"; }'
            .' $ok = true; while ($ok) { echo "yes"; $ok = false; }'
        ));
    }

    public function test_code_gen_pushes_boolean_literals()
    {
        $this->assertEquals("PUSH true\nPUSH false\nEQUALS\nPRINT", $this->generateCode('echo true == false;'));
    }
}
