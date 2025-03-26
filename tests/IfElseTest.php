<?php

use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;
use GazLang\Interpreter\Interpreter;
use PHPUnit\Framework\TestCase;

class IfElseTest extends TestCase
{
    public function testIfElse()
    {
        $code = <<<CODE
        if (1) {
            echo 42;
        } else {
            echo 0;
        }
        CODE;

        $lexer = new Lexer($code);
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        $this->expectOutputString("42\n");
        $interpreter->interpret();
    }

    public function testIfElseFalseCondition()
    {
        $code = <<<CODE
        if (0) {
            echo 42;
        } else {
            echo 0;
        }
        CODE;

        $lexer = new Lexer($code);
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        $this->expectOutputString("0\n");
        $interpreter->interpret();
    }
    
    public function testNestedIfElse()
    {
        $code = <<<CODE
        if (1) {
            if (0) {
                echo 10;
            } else {
                echo 20;
            }
        } else {
            echo 30;
        }
        CODE;

        $lexer = new Lexer($code);
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        $this->expectOutputString("20\n");
        $interpreter->interpret();
    }
    
    public function testVariableInCondition()
    {
        $code = <<<'CODE'
        $x = 5;
        if ($x) {
            echo 100;
        } else {
            echo 200;
        }
        
        $y = 0;
        if ($y) {
            echo 300;
        } else {
            echo 400;
        }
        CODE;

        $lexer = new Lexer($code);
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        $this->expectOutputString("100\n400\n");
        $interpreter->interpret();
    }
    
    public function testElseIf()
    {
        $code = <<<CODE
        if (0) {
            echo 10;
        } else if (1) {
            echo 20;
        } else {
            echo 30;
        }
        CODE;

        $lexer = new Lexer($code);
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        $this->expectOutputString("20\n");
        $interpreter->interpret();
    }
    
    public function testMultipleElseIf()
    {
        $code = <<<CODE
        if (0) {
            echo 100;
        } else if (0) {
            echo 200;
        } else if (1) {
            echo 300;
        } else {
            echo 400;
        }
        CODE;

        $lexer = new Lexer($code);
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        $this->expectOutputString("300\n");
        $interpreter->interpret();
    }
    
    public function testNestedElseIf()
    {
        $code = <<<CODE
        if (1) {
            if (0) {
                echo 500;
            } else if (1) {
                echo 600;
            } else {
                echo 700;
            }
        } else {
            echo 800;
        }
        CODE;

        $lexer = new Lexer($code);
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        $this->expectOutputString("600\n");
        $interpreter->interpret();
    }
}
