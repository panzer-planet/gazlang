<?php

namespace GazLang\Tests;

use GazLang\Interpreter\Interpreter;
use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;
use PHPUnit\Framework\TestCase;

class BooleanTest extends TestCase
{
    public function test_boolean_literals()
    {
        $code = <<<'CODE'
        echo true;
        echo false;
        CODE;

        $lexer = new Lexer($code);
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        $this->expectOutputString("1\n0\n");
        $interpreter->interpret();
    }

    public function test_boolean_assignment()
    {
        $code = <<<'CODE'
        $a = true;
        $b = false;
        echo $a;
        echo $b;
        CODE;

        $lexer = new Lexer($code);
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        $this->expectOutputString("1\n0\n");
        $interpreter->interpret();
    }

    public function test_boolean_in_condition()
    {
        $code = <<<'CODE'
        if (true) {
            echo 42;
        } else {
            echo 0;
        }
        
        if (false) {
            echo 100;
        } else {
            echo 200;
        }
        CODE;

        $lexer = new Lexer($code);
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        $this->expectOutputString("42\n200\n");
        $interpreter->interpret();
    }

    public function test_boolean_type_coercion()
    {
        $code = <<<'CODE'
        // Integers coerced to booleans
        if (1) {
            echo "non-zero is true";
        }
        
        if (0) {
            echo "zero is true";
        } else {
            echo "zero is false";
        }
        
        // Strings coerced to booleans
        $emptyString = "";
        $nonEmptyString = "hello";
        
        if ($nonEmptyString) {
            echo "non-empty string is true";
        }
        
        if ($emptyString) {
            echo "empty string is true";
        } else {
            echo "empty string is false";
        }
        CODE;

        $lexer = new Lexer($code);
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        $this->expectOutputString("non-zero is true\nzero is false\nnon-empty string is true\nempty string is false\n");
        $interpreter->interpret();
    }

    public function test_boolean_operations()
    {
        $code = <<<'CODE'
        // AND operation
        echo true && true;   // 1
        echo true && false;  // 0
        echo false && true;  // 0
        echo false && false; // 0
        
        // OR operation
        echo true || true;   // 1
        echo true || false;  // 1
        echo false || true;  // 1
        echo false || false; // 0
        
        // NOT operation
        echo !true;          // 0
        echo !false;         // 1
        CODE;

        $lexer = new Lexer($code);
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        $this->expectOutputString("1\n0\n0\n0\n1\n1\n1\n0\n0\n1\n");
        $interpreter->interpret();
    }

    public function test_equality_operator()
    {
        $code = <<<'CODE'
        echo true == true;   // 1
        echo true == false;  // 0
        echo false == true;  // 0
        echo false == false; // 1
        
        echo true != true;   // 0
        echo true != false;  // 1
        echo false != true;  // 1
        echo false != false; // 0
        
        echo 1 == true;      // 1
        echo 0 == false;     // 1
        echo 1 == false;     // 0
        echo 0 == true;      // 0
        
        echo 1 != true;      // 0
        echo 0 != false;     // 0
        echo 1 != false;     // 1
        echo 0 != true;      // 1
        
        echo "true" == true; // 1
        echo "false" == false; // 0
        echo "" == false; // 1
        CODE;

        $lexer = new Lexer($code);
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        $this->expectOutputString("1\n0\n0\n1\n0\n1\n1\n0\n1\n1\n0\n0\n0\n0\n1\n1\n1\n0\n1\n");
        $interpreter->interpret();
    }
}
