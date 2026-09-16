<?php

namespace GazLang\Tests;

use GazLang\Interpreter\Interpreter;
use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;
use PHPUnit\Framework\TestCase;

class InterpreterTest extends TestCase
{
    public function test_simple_expression()
    {
        $lexer = new Lexer('3 + 4;');
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        // Should not throw any exceptions
        $this->assertNull($interpreter->interpret());
    }

    public function test_complex_expression()
    {
        $lexer = new Lexer('3 + 4 * 2 - 6 / 2;');
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        // Should not throw any exceptions
        $this->assertNull($interpreter->interpret());
    }

    public function test_multiple_statements()
    {
        $lexer = new Lexer('5 + 3; 10 * 2;');
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        // Should not throw any exceptions
        $this->assertNull($interpreter->interpret());
    }

    public function test_echo_statement()
    {
        $lexer = new Lexer('echo 3 + 4;');
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        // Capture output to test echo statement
        ob_start();
        $interpreter->interpret();
        $output = ob_get_clean();

        $this->assertEquals('7'.PHP_EOL, $output);
    }

    public function test_mixed_statements()
    {
        $lexer = new Lexer('5 + 3; echo 10 * 2; 7 - 2;');
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        // Capture output to test echo statement
        ob_start();
        $interpreter->interpret();
        $output = ob_get_clean();

        $this->assertEquals('20'.PHP_EOL, $output);
    }

    public function test_multiple_echo_statements()
    {
        $lexer = new Lexer('echo 5 + 3; echo 10 * 2;');
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        // Capture output to test echo statements
        ob_start();
        $interpreter->interpret();
        $output = ob_get_clean();

        $this->assertEquals('8'.PHP_EOL.'20'.PHP_EOL, $output);
    }

    public function test_parentheses_in_expressions()
    {
        $lexer = new Lexer('echo (5 + 3) * 2; echo 10 / (2 + 3);');
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);

        // Capture output to test parentheses
        ob_start();
        $interpreter->interpret();
        $output = ob_get_clean();

        $this->assertEquals('16'.PHP_EOL.'2'.PHP_EOL, $output);
    }
}
