<?php

namespace GazLang\Tests;

use GazLang\CodeGenerator\CodeGenerator;
use GazLang\Interpreter\Interpreter;
use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for GazLang tests with helper methods
 */
abstract class GazLangTestCase extends TestCase
{
    /**
     * Create a lexer for the given input
     *
     * @param  string  $input  The GazLang code to parse
     */
    protected function createLexer(string $input): Lexer
    {
        return new Lexer($input);
    }

    /**
     * Create a parser for the given input
     *
     * @param  string  $input  The GazLang code to parse
     */
    protected function createParser(string $input): Parser
    {
        $lexer = $this->createLexer($input);

        return new Parser($lexer);
    }

    /**
     * Create an interpreter for the given input
     *
     * @param  string  $input  The GazLang code to interpret
     */
    protected function createInterpreter(string $input): Interpreter
    {
        $parser = $this->createParser($input);

        return new Interpreter($parser);
    }

    /**
     * Execute GazLang code and return the output
     *
     * @param  string  $input  The GazLang code to execute
     * @return string The output from echo statements
     */
    protected function executeCode(string $input): string
    {
        $interpreter = $this->createInterpreter($input);

        ob_start();
        $interpreter->interpret();

        return ob_get_clean();
    }

    /**
     * Generate VM code for the given input
     *
     * @param  string  $input  The GazLang code
     * @return string The generated VM code
     */
    protected function generateCode(string $input): string
    {
        $parser = $this->createParser($input);
        $tree = $parser->parse();

        $generator = new CodeGenerator($tree);

        return $generator->generate();
    }
}
