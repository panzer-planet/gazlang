<?php

namespace GazLang\Tests;

use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;
use GazLang\CodeGenerator\CodeGenerator;
use PHPUnit\Framework\TestCase;

class CodeGeneratorTest extends TestCase
{
    public function testSimpleExpression()
    {
        $lexer = new Lexer('3 + 4;');
        $parser = new Parser($lexer);
        $codeGenerator = new CodeGenerator($parser->parse());
        
        $code = $codeGenerator->generate();
        $expectedCode = "PUSH 3\nPUSH 4\nADD_OR_CONCAT\nPOP";
        
        $this->assertEquals($expectedCode, $code);
    }
    
    public function testComplexExpression()
    {
        $lexer = new Lexer('3 + 4 * 2;');
        $parser = new Parser($lexer);
        $codeGenerator = new CodeGenerator($parser->parse());
        
        $code = $codeGenerator->generate();
        $expectedCode = "PUSH 3\nPUSH 4\nPUSH 2\nMUL\nADD_OR_CONCAT\nPOP";
        
        $this->assertEquals($expectedCode, $code);
    }
    
    public function testMultipleStatements()
    {
        $lexer = new Lexer('5 + 3; 10 * 2;');
        $parser = new Parser($lexer);
        $codeGenerator = new CodeGenerator($parser->parse());
        
        $code = $codeGenerator->generate();
        $expectedCode = "PUSH 5\nPUSH 3\nADD_OR_CONCAT\nPOP\nPUSH 10\nPUSH 2\nMUL\nPOP";
        
        $this->assertEquals($expectedCode, $code);
    }
    
    public function testEchoStatement()
    {
        $lexer = new Lexer('echo 3 + 4;');
        $parser = new Parser($lexer);
        $codeGenerator = new CodeGenerator($parser->parse());
        
        $code = $codeGenerator->generate();
        $expectedCode = "PUSH 3\nPUSH 4\nADD_OR_CONCAT\nPRINT";
        
        $this->assertEquals($expectedCode, $code);
    }
    
    public function testMixedStatements()
    {
        $lexer = new Lexer('5 + 3; echo 10 * 2; 7 - 2;');
        $parser = new Parser($lexer);
        $codeGenerator = new CodeGenerator($parser->parse());
        
        $code = $codeGenerator->generate();
        $expectedCode = "PUSH 5\nPUSH 3\nADD_OR_CONCAT\nPOP\nPUSH 10\nPUSH 2\nMUL\nPRINT\nPUSH 7\nPUSH 2\nSUB\nPOP";
        
        $this->assertEquals($expectedCode, $code);
    }
} 