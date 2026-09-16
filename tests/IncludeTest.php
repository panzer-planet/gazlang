<?php

namespace GazLang\Tests;

use GazLang\CodeGenerator\CodeGenerator;
use GazLang\Interpreter\Interpreter;
use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;

class IncludeTest extends GazLangTestCase
{
    private const FIXTURES = __DIR__.'/fixtures/include/';

    private function parserFor(string $file): Parser
    {
        return new Parser(new Lexer(file_get_contents(self::FIXTURES.$file)), self::FIXTURES.$file);
    }

    private function runFile(string $file): string
    {
        ob_start();
        try {
            (new Interpreter($this->parserFor($file)))->interpret();
        } finally {
            $output = ob_get_clean();
        }

        return $output;
    }

    public function test_included_files_run_in_place_once_relative_to_the_including_file()
    {
        // main includes lib/math, which includes ../cycle, whose include of lib/math is skipped
        $this->assertEquals("cycle runs first\nmain sees math\n9\n", $this->runFile('main.gaz'));
    }

    public function test_including_the_main_file_again_is_skipped()
    {
        $this->assertEquals("cycle runs first\n", $this->runFile('cycle.gaz'));
    }

    public function test_syntax_errors_name_the_included_file()
    {
        $this->expectExceptionMessage('Invalid syntax near token: SEMICOLON(;) (in lib/bad.gaz)');
        $this->runFile('broken.gaz');
    }

    public function test_missing_file()
    {
        $this->expectExceptionMessage('Cannot include file: nope.gaz');
        $this->createParser('include "nope.gaz";')->parse();
    }

    public function test_unreadable_file_is_an_error_not_an_empty_file()
    {
        $dir = sys_get_temp_dir().'/gazlang_include_'.getmypid();
        mkdir($dir);
        file_put_contents("{$dir}/main.gaz", 'include "secret.gaz";');
        file_put_contents("{$dir}/secret.gaz", 'function f() { return 1; }');
        chmod("{$dir}/secret.gaz", 0);

        try {
            if (is_readable("{$dir}/secret.gaz")) {
                $this->markTestSkipped('Running as a user that can read any file');
            }
            $this->expectExceptionMessage('Cannot include file: secret.gaz');
            (new Parser(new Lexer('include "secret.gaz";'), "{$dir}/main.gaz"))->parse();
        } finally {
            chmod("{$dir}/secret.gaz", 0644);
            unlink("{$dir}/secret.gaz");
            unlink("{$dir}/main.gaz");
            rmdir($dir);
        }
    }

    public function test_include_must_be_at_the_top_level()
    {
        $this->expectExceptionMessage('include can only be used at the top level');
        $this->runFile('nested.gaz');
    }

    public function test_include_path_must_be_a_string_literal()
    {
        $this->expectExceptionMessage('Invalid syntax near token: VAR_IDENTIFIER($file)');
        $this->createParser('include $file;')->parse();
    }

    public function test_functions_from_included_files_are_code_generated()
    {
        $code = (new CodeGenerator($this->parserFor('main.gaz')->parse()))->generate();

        $this->assertStringContainsString("CALL FN_square 1\nPRINT\nHALT\nLABEL FN_square", $code);
    }
}
