<?php

namespace GazLang\Tests;

use GazLang\Interpreter\Interpreter;
use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;
use PHPUnit\Framework\TestCase;

class StringTest extends TestCase
{
    public function test_simple_string()
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

    public function test_string_assignment()
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

    public function test_string_concatenation()
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

    public function test_mixed_type_operations()
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

    public function test_escape_sequences()
    {
        $code = <<<'CODE'
        echo "Hello, \"GazLang\"!";
        echo "This is a new line\n";
        echo "This is a tab\t";
        echo "This is a backslash\\";
        CODE;

        $lexer = new Lexer($code);
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        $this->expectOutputString("Hello, \"GazLang\"!\nThis is a new line\n\nThis is a tab\t\nThis is a backslash\\\n");
        $interpreter->interpret();
    }
}
