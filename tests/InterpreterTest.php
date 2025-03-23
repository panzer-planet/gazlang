<?php

namespace GazLang\Tests;

use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;
use GazLang\Interpreter\Interpreter;
use PHPUnit\Framework\TestCase;

class InterpreterTest extends TestCase
{
    public function testSimpleExpression()
    {
        $lexer = new Lexer('3 + 4;');
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);
        
        // Should not throw any exceptions
        $this->assertNull($interpreter->interpret());
    }
    
    public function testComplexExpression()
    {
        $lexer = new Lexer('3 + 4 * 2 - 6 / 2;');
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);
        
        // Should not throw any exceptions
        $this->assertNull($interpreter->interpret());
    }
    
    public function testMultipleStatements()
    {
        $lexer = new Lexer('5 + 3; 10 * 2;');
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);
        
        // Should not throw any exceptions
        $this->assertNull($interpreter->interpret());
    }
    
    public function testEchoStatement()
    {
        $lexer = new Lexer('echo 3 + 4;');
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);
        
        // Capture output to test echo statement
        ob_start();
        $interpreter->interpret();
        $output = ob_get_clean();
        
        $this->assertEquals("7" . PHP_EOL, $output);
    }
    
    public function testMixedStatements()
    {
        $lexer = new Lexer('5 + 3; echo 10 * 2; 7 - 2;');
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);
        
        // Capture output to test echo statement
        ob_start();
        $interpreter->interpret();
        $output = ob_get_clean();
        
        $this->assertEquals("20" . PHP_EOL, $output);
    }
    
    public function testMultipleEchoStatements()
    {
        $lexer = new Lexer('echo 5 + 3; echo 10 * 2;');
        $parser = new Parser($lexer);
        $interpreter = new Interpreter($parser);
        
        // Capture output to test echo statements
        ob_start();
        $interpreter->interpret();
        $output = ob_get_clean();
        
        $this->assertEquals("8" . PHP_EOL . "20" . PHP_EOL, $output);
    }
} 