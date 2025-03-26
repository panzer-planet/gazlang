<?php

use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;
use GazLang\Interpreter\Interpreter;
use PHPUnit\Framework\TestCase;

class StringTest extends TestCase
{
    public function testSimpleString()
    {
        $code = <<<'CODE'
        echo "Hello, World!";
        CODE;

        $lexer = new Lexer($code);
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        $this->expectOutputString("Hello, World!\n");
        $interpreter->interpret();
    }
    
    public function testStringAssignment()
    {
        $code = <<<'CODE'
        $message = "Hello, GazLang!";
        echo $message;
        CODE;

        $lexer = new Lexer($code);
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        $this->expectOutputString("Hello, GazLang!\n");
        $interpreter->interpret();
    }
    
    public function testStringConcatenation()
    {
        $code = <<<'CODE'
        echo "Hello, " + "World!";
        $prefix = "GazLang ";
        $suffix = "is awesome";
        echo $prefix + $suffix;
        CODE;

        $lexer = new Lexer($code);
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        $this->expectOutputString("Hello, World!\nGazLang is awesome\n");
        $interpreter->interpret();
    }
    
    public function testMixedTypeOperations()
    {
        $code = <<<'CODE'
        // String + number concatenates by converting number to string
        echo "Count: " + 42;
        
        // Number + string concatenates by converting number to string
        echo 2022 + " is the year";
        
        // Using variable
        $year = 2025;
        echo "The year is " + $year;
        CODE;

        $lexer = new Lexer($code);
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        $this->expectOutputString("Count: 42\n2022 is the year\nThe year is 2025\n");
        $interpreter->interpret();
    }
}