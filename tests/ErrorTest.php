<?php

namespace GazLang\Tests;

use GazLang\GazLangError;
use GazLang\Interpreter\Interpreter;
use GazLang\Lexer\Lexer;
use GazLang\Parser\Parser;

class ErrorTest extends GazLangTestCase
{
    /**
     * @dataProvider syntaxErrors
     */
    public function test_syntax_errors_say_what_and_where(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->createParser($code)->parse();
    }

    public static function syntaxErrors(): array
    {
        return [
            'bad character' => ["echo 1;\n\necho 2 ` 3;", "Unexpected character '`' on line 3"],
            'unterminated string, at its start' => ["echo 1;\necho \"abc\n\ndef;", 'Unterminated string on line 2'],
            'missing token' => ["echo 1;\necho (2;", "Expected ')' but found ';' on line 2"],
            'unexpected token' => ['echo ;', "Unexpected ';' on line 1"],
            'end of file' => ["echo (1\n", "Expected ')' but found end of file on line 2"],
            'string token' => ['echo 1 "x\ty";', 'Expected \';\' but found string "x\ty" on line 1'],
            'keyword expected' => ['fn f() { } fn g() { } echo 1 if', "Expected ';' but found 'if' on line 1"],
            'comments count as lines' => ["// one\n// two\necho $;", 'Invalid variable name: $ on line 3'],
            'undefined function, at the call' => ["fn f() {}\n\nmissing();", 'Undefined function: missing on line 3'],
            'interpolation not closed by }' => ['echo "a {$x $y} b";', "Expected '}' but found '\$y' on line 1"],
            'empty interpolation' => ['echo "a {$}";', 'Invalid variable name: $ on line 1'],
            'include with interpolation' => ["\$f = 1;\ninclude \"lib/{\$f}.gaz\";", 'include paths cannot use interpolation on line 2'],
            'duplicate parameter' => ["fn f(\$a,\n \$a) {}", 'Duplicate parameter $a in function f on line 2'],
        ];
    }

    /**
     * @dataProvider runtimeErrors
     */
    public function test_runtime_errors_point_at_the_innermost_node(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode($code);
    }

    public static function runtimeErrors(): array
    {
        return [
            'operator on a later line' => ["fn f(\$x) {\n    return \$x +\n        null;\n}\necho f(1);", 'Cannot use + on null on line 2'],
            'undefined variable' => ["\$a = 1;\necho \$a + \$b;", 'Undefined variable: $b on line 2'],
            'division by zero' => ["\$zero = 0;\necho 1 / \$zero;", 'Division by zero on line 2'],
            'builtin argument' => ["echo\n  lower(1);", 'lower() expects string, got int on line 2'],
            'inside a for step' => ['for ($i = 0; $i < 2; $i = $i + null) { }', 'Cannot use + on null on line 1'],
        ];
    }

    public function test_messages_are_exact_with_no_trailing_location_duplicated()
    {
        try {
            $this->executeCode("\$x = 1;\n\$x[0] = 2;");
            $this->fail('Expected an error');
        } catch (GazLangError $e) {
            $this->assertSame('Cannot use [] on int on line 2', $e->getMessage());
            $this->assertSame('Cannot use [] on int', $e->reason);
            $this->assertSame(2, $e->line_number);
        }
    }

    public function test_error_builtin_message_is_printed_as_is()
    {
        try {
            $this->executeCode("\n\nerror(\"Syntax error in input on line 7\");");
            $this->fail('Expected an error');
        } catch (GazLangError $e) {
            $this->assertSame('Syntax error in input on line 7', $e->getMessage());
        }
    }

    public function test_errors_in_included_files_name_the_file()
    {
        $path = __DIR__.'/fixtures/include/runtime.gaz';
        $this->expectExceptionMessage('Cannot use + on null at tests/fixtures/include/lib/fails.gaz:2');

        (new Interpreter(new Parser(new Lexer(file_get_contents($path)), $path)))->interpret();
    }

    public function test_cli_shows_the_file_and_line()
    {
        exec(sprintf(
            'cd %s && %s bin/gazlang -f tests/fixtures/include/runtime.gaz 2>&1',
            escapeshellarg(__DIR__.'/..'),
            escapeshellarg(PHP_BINARY)
        ), $output, $exit_code);

        // The trace names the call in the included file and where it was called from
        $this->assertSame([
            'Error: Cannot use + on null at tests/fixtures/include/lib/fails.gaz:2',
            '  fails at tests/fixtures/include/lib/fails.gaz:2',
            '  top level at tests/fixtures/include/runtime.gaz:2',
        ], $output);
        $this->assertSame(1, $exit_code);
    }
}
