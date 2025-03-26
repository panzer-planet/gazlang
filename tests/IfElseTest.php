<?php

use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;
use GazLang\Interpreter\Interpreter;

class IfElseTest extends \PHPUnit\Framework\TestCase
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
}
