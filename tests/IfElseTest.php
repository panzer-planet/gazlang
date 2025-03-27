<?php

namespace GazLang\Tests;

use GazLang\Interpreter\Interpreter;
use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;
use PHPUnit\Framework\TestCase;

class IfElseTest extends TestCase
{
    public function test_if_else()
    {
        $code = <<<'CODE'
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

    public function test_if_else_false_condition()
    {
        $code = <<<'CODE'
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

    public function test_nested_if_else()
    {
        $code = <<<'CODE'
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

    public function test_variable_in_condition()
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

    public function test_else_if()
    {
        $code = <<<'CODE'
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

    public function test_multiple_else_if()
    {
        $code = <<<'CODE'
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

    public function test_nested_else_if()
    {
        $code = <<<'CODE'
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
