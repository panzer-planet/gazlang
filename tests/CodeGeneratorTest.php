<?php

namespace GazLang\Tests;

use GazLang\CodeGenerator\CodeGenerator;
use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;
use PHPUnit\Framework\TestCase;

class CodeGeneratorTest extends TestCase
{
    public function test_simple_expression()
    {
        $lexer = new Lexer('3 + 4;');
        $parser = new Parser($lexer);
        $codeGenerator = new CodeGenerator($parser->parse());

        $code = $codeGenerator->generate();
        $expectedCode = "PUSH 3\nPUSH 4\nADD_OR_CONCAT\nPOP";

        $this->assertEquals($expectedCode, $code);
    }

    public function test_complex_expression()
    {
        $lexer = new Lexer('3 + 4 * 2;');
        $parser = new Parser($lexer);
        $codeGenerator = new CodeGenerator($parser->parse());

        $code = $codeGenerator->generate();
        $expectedCode = "PUSH 3\nPUSH 4\nPUSH 2\nMUL\nADD_OR_CONCAT\nPOP";

        $this->assertEquals($expectedCode, $code);
    }

    public function test_multiple_statements()
    {
        $lexer = new Lexer('5 + 3; 10 * 2;');
        $parser = new Parser($lexer);
        $codeGenerator = new CodeGenerator($parser->parse());

        $code = $codeGenerator->generate();
        $expectedCode = "PUSH 5\nPUSH 3\nADD_OR_CONCAT\nPOP\nPUSH 10\nPUSH 2\nMUL\nPOP";

        $this->assertEquals($expectedCode, $code);
    }

    public function test_echo_statement()
    {
        $lexer = new Lexer('echo 3 + 4;');
        $parser = new Parser($lexer);
        $codeGenerator = new CodeGenerator($parser->parse());

        $code = $codeGenerator->generate();
        $expectedCode = "PUSH 3\nPUSH 4\nADD_OR_CONCAT\nPRINT";

        $this->assertEquals($expectedCode, $code);
    }

    public function test_mixed_statements()
    {
        $lexer = new Lexer('5 + 3; echo 10 * 2; 7 - 2;');
        $parser = new Parser($lexer);
        $codeGenerator = new CodeGenerator($parser->parse());

        $code = $codeGenerator->generate();
        $expectedCode = "PUSH 5\nPUSH 3\nADD_OR_CONCAT\nPOP\nPUSH 10\nPUSH 2\nMUL\nPRINT\nPUSH 7\nPUSH 2\nSUB\nPOP";

        $this->assertEquals($expectedCode, $code);
    }

    public function test_equality_operator()
    {
        $lexer = new Lexer('echo 1 == 1; echo 1 == 2; echo 1 != 1; echo 1 != 2;');
        $parser = new Parser($lexer);
        $codeGenerator = new CodeGenerator($parser->parse());

        $code = $codeGenerator->generate();
        $expectedCode = "PUSH 1\nPUSH 1\nEQUALS\nPRINT\nPUSH 1\nPUSH 2\nEQUALS\nPRINT\nPUSH 1\nPUSH 1\nNOT_EQUALS\nPRINT\nPUSH 1\nPUSH 2\nNOT_EQUALS\nPRINT";

        $this->assertEquals($expectedCode, $code);
    }

    public function test_and_operator()
    {
        $lexer = new Lexer('echo true && true; echo true && false; echo false && true; echo false && false;');
        $parser = new Parser($lexer);
        $codeGenerator = new CodeGenerator($parser->parse());

        $code = $codeGenerator->generate();
        $expectedCode = "PUSH 1\nPUSH 1\nAND\nPRINT\nPUSH 1\nPUSH 0\nAND\nPRINT\nPUSH 0\nPUSH 1\nAND\nPRINT\nPUSH 0\nPUSH 0\nAND\nPRINT";

        $this->assertEquals($expectedCode, $code);
    }
}
