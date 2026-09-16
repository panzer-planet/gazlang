<?php

namespace GazLang\Tests;

use GazLang\CodeGenerator\CodeGenerator;
use GazLang\Interpreter\Interpreter;
use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Base test case for GazLang tests with helper methods
 */
abstract class GazLangTestCase extends TestCase
{
    protected const ROOT = __DIR__.'/..';

    /**
     * Run a GazLang file as `php bin/gazlang -f FILE -- ARGS` would, but in-process (a CLI run costs ~0.5s)
     *
     * Runs from the project root, so FILE is relative to it and errors name files the
     * same way; errors are printed as "Error: <message>" and give exit code 1. Deep
     * recursion can segfault in-process while pcov is loaded, so test that through the CLI.
     *
     * @param  string  $file  Path relative to the project root
     * @param  string[]  $args  Arguments returned by args()
     * @return array{0: string, 1: int} The output and exit code
     */
    protected function runProgram(string $file, array $args = []): array
    {
        $cwd = getcwd();
        chdir(self::ROOT);
        ob_start();

        try {
            $parser = new Parser(new Lexer(file_get_contents($file)), $file);
            (new Interpreter($parser, $args))->interpret();
            $exit_code = 0;
        } catch (Throwable $e) {
            echo "Error: {$e->getMessage()}\n";
            $exit_code = 1;
        } finally {
            $output = ob_get_clean();
            chdir($cwd);
        }

        return [$output, $exit_code];
    }

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
        try {
            $interpreter->interpret();
        } finally {
            $output = ob_get_clean();
        }

        return $output;
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
