<?php

namespace GazLang\Tests;

class CodeGeneratorTest extends GazLangTestCase
{
    public function test_simple_expression()
    {
        $code = $this->generateCode('3 + 4;');
        $expectedCode = "PUSH 3\nPUSH 4\nADD\nPOP";

        $this->assertEquals($expectedCode, $code);
    }

    public function test_complex_expression()
    {
        $code = $this->generateCode('3 + 4 * 2;');
        $expectedCode = "PUSH 3\nPUSH 4\nPUSH 2\nMUL\nADD\nPOP";

        $this->assertEquals($expectedCode, $code);
    }

    public function test_multiple_statements()
    {
        $code = $this->generateCode('5 + 3; 10 * 2;');
        $expectedCode = "PUSH 5\nPUSH 3\nADD\nPOP\nPUSH 10\nPUSH 2\nMUL\nPOP";

        $this->assertEquals($expectedCode, $code);
    }

    public function test_echo_statement()
    {
        $code = $this->generateCode('echo 3 + 4;');
        $expectedCode = "PUSH 3\nPUSH 4\nADD\nPRINT";

        $this->assertEquals($expectedCode, $code);
    }

    public function test_mixed_statements()
    {
        $code = $this->generateCode('5 + 3; echo 10 * 2; 7 - 2;');
        $expectedCode = "PUSH 5\nPUSH 3\nADD\nPOP\nPUSH 10\nPUSH 2\nMUL\nPRINT\nPUSH 7\nPUSH 2\nSUB\nPOP";

        $this->assertEquals($expectedCode, $code);
    }
}
