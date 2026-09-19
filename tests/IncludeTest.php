<?php

namespace GazLang\Tests;

class IncludeTest extends GazLangTestCase
{
    private const FIXTURES = 'tests/fixtures/include/';

    private function runFile(string $file): string
    {
        return self::succeed(['-f', self::FIXTURES.$file]);
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
        $this->expectExceptionMessage("Expected ')' but found ';' at tests/fixtures/include/lib/bad.gaz:1");
        $this->runFile('broken.gaz');
    }

    public function test_missing_file()
    {
        $this->expectExceptionMessage('Cannot include file: nope.gaz');
        $this->parse('include "nope.gaz";');
    }

    public function test_unreadable_file_is_an_error_not_an_empty_file()
    {
        $dir = sys_get_temp_dir().'/gazlang_include_'.getmypid();
        mkdir($dir);
        file_put_contents("{$dir}/main.gaz", 'include "secret.gaz";');
        file_put_contents("{$dir}/secret.gaz", 'fn f() { return 1; }');
        chmod("{$dir}/secret.gaz", 0);

        try {
            if (is_readable("{$dir}/secret.gaz")) {
                $this->markTestSkipped('Running as a user that can read any file');
            }
            $this->expectExceptionMessage('Cannot include file: secret.gaz');
            self::succeed(['--ast', '-f', "{$dir}/main.gaz"]);
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
        $this->expectExceptionMessage("Expected a string but found '\$file'");
        $this->parse('include $file;');
    }

    public function test_functions_from_included_files_are_code_generated()
    {
        $code = self::succeed(['-c', '-f', self::FIXTURES.'main.gaz']);

        // The included function is a block of its own, like any other
        $this->assertStringContainsString("CALL square 1\nPRINT\n", $code);
        $this->assertStringContainsString("\nfn square 1 1\n", $code);
    }
}
