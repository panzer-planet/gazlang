<?php

namespace GazLang\Tests;

/**
 * import: what a file sees is what it imports, each module is read once, only the main file runs
 * statements, and the standard library is one module however it is reached
 */
class ImportTest extends GazLangTestCase
{
    private const FIXTURES = 'tests/fixtures/import/';

    private function runFile(string $file): string
    {
        return self::succeed(['-f', self::FIXTURES.$file]);
    }

    public function test_imported_modules_are_read_once_and_a_cycle_of_them_is_allowed()
    {
        // main imports lib/math twice; lib/math imports lib/numbers, which imports lib/math back
        $this->assertEquals("9\n8\n", $this->runFile('main.gaz'));
    }

    public function test_syntax_errors_name_the_imported_file()
    {
        $this->expectExceptionMessage("Expected ')' but found ';' at tests/fixtures/import/lib/bad.gaz:2");
        $this->runFile('broken.gaz');
    }

    public function test_runtime_errors_name_the_imported_file()
    {
        $this->expectExceptionMessage('at tests/fixtures/import/lib/fails.gaz:2');
        $this->runFile('runtime.gaz');
    }

    public function test_missing_file()
    {
        $this->expectExceptionMessage('Cannot import "./nope.gaz": there is no such file');
        $this->parse('import "./nope.gaz";');
    }

    public function test_unreadable_file_is_an_error_not_an_empty_file()
    {
        $dir = sys_get_temp_dir().'/gazlang_import_'.getmypid();
        mkdir($dir);
        file_put_contents("{$dir}/main.gaz", 'import "./secret.gaz";');
        file_put_contents("{$dir}/secret.gaz", 'fn f() { return 1; }');
        chmod("{$dir}/secret.gaz", 0);

        try {
            if (is_readable("{$dir}/secret.gaz")) {
                $this->markTestSkipped('Running as a user that can read any file');
            }
            $this->expectExceptionMessage('Cannot import "./secret.gaz": it can\'t be read');
            self::succeed(['--ast', '-f', "{$dir}/main.gaz"]);
        } finally {
            chmod("{$dir}/secret.gaz", 0644);
            unlink("{$dir}/secret.gaz");
            unlink("{$dir}/main.gaz");
            rmdir($dir);
        }
    }

    public function test_import_must_be_at_the_top_of_a_file()
    {
        $this->expectExceptionMessage('import can only be used at the top of a file');
        $this->runFile('nested.gaz');
    }

    public function test_import_path_must_be_a_string_literal()
    {
        $this->expectExceptionMessage("Expected a string but found '\$file'");
        $this->parse('import $file;');
    }

    public function test_include_is_gone_and_says_what_replaced_it()
    {
        $this->expectExceptionMessage('include is gone: write import "std/json.gaz"; (a path is from the project root, or ./ for this file\'s directory) on line 1');
        $this->parse('include "std/json.gaz";');
    }

    public function test_functions_from_imported_files_are_code_generated()
    {
        $code = self::succeed(['-c', '-f', self::FIXTURES.'main.gaz']);

        // The imported function is a block of its own, like any other
        $this->assertStringContainsString("CALL square 1\nPRINT\n", $code);
        $this->assertStringContainsString("\nfn square 1 1\n", $code);
    }

    /**
     * A module only declares, so compiled on its own it is a program that does nothing, and
     * whether it compiles depends on its own imports alone
     */
    public function test_a_module_compiles_alone_and_does_nothing()
    {
        $this->assertSame('', $this->runFile('lib/math.gaz'));
        $this->assertStringContainsString("\nfn times 2 2\n", self::succeed(['-c', '-f', self::FIXTURES.'lib/math.gaz']));
    }

    /**
     * Alone, nothing declares square(); in the program, lib/math.gaz does, and the error says to
     * import it. Either way the mistake is at the same line.
     */
    public function test_a_missing_import_fails_alone_and_in_its_program_at_the_same_line()
    {
        $at = ' at tests/fixtures/import/lib/needs_math.gaz:4';
        $errors = [
            'lib/needs_math.gaz' => "Error: Undefined function: square{$at}\n",
            'needs_math.gaz' => "Error: Undefined function: square (square is declared in tests/fixtures/import/lib/math.gaz, which this file doesn't import: add import \"./math.gaz\";){$at}\n",
        ];
        foreach ($errors as $file => $error) {
            [, $err, $code] = self::gazlang(['-c', '-f', self::FIXTURES.$file]);
            $this->assertSame(1, $code, $file);
            $this->assertSame($error, $err, $file);
        }
    }

    /**
     * Under GAZLIB, std/ is read from that directory, and a file in it is the standard library's:
     * lib/text.gaz imported by its path is std/text.gaz, one module. Without it the two are
     * different files, and the namespace belongs to the standard library.
     */
    public function test_a_file_of_gazlib_is_the_standard_librarys_module()
    {
        $program = "import \"std/text.gaz\";\nimport \"lib/text.gaz\";\n\necho text::quote(\"x\");\n";

        $this->assertSame("\"x\"\n", self::succeed(['-'], $program, ['GAZLIB' => 'lib']));

        [, $err, $code] = self::gazlang(['-'], $program);
        $this->assertSame(1, $code);
        $this->assertStringStartsWith('Error: Namespace text is std/text.gaz\'s: lib/text.gaz can\'t declare it too (a namespace belongs to one project) at lib/text.gaz:', $err);
    }
}
