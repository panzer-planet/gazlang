<?php

namespace GazLang\Tests;

use GazLang\GazLangError;

class TryCatchTest extends GazLangTestCase
{
    public function test_running_out_of_call_depth_can_be_caught()
    {
        // Through the CLI, which runs without pcov: in-process, recursion this deep can segfault
        exec(sprintf(
            'echo %s | %s %s',
            escapeshellarg('function recurse() { return recurse(); } try { recurse(); } catch ($e) { echo $e["message"]; } echo "still running";'),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::ROOT.'/bin/gazlang')
        ), $output, $exit_code);

        $this->assertSame(['Maximum call depth of 10000 exceeded calling recurse', 'still running'], $output);
        $this->assertSame(0, $exit_code);
    }

    public function test_error_in_a_catch_block_is_not_caught_by_the_same_try()
    {
        $this->expectExceptionMessage('Cannot use * on string on line 3');
        $this->executeCode("try {\n    error(\"first\");\n} catch (\$e) { \$x = \"a\" * 2; }");
    }

    public function test_uncaught_error_still_prints_its_message_exactly()
    {
        try {
            $this->executeCode("\n\nerror(\"Syntax error in input on line 7\");");
            $this->fail('Expected an error');
        } catch (GazLangError $e) {
            $this->assertSame('Syntax error in input on line 7', $e->getMessage());
            // The location is still recorded, for catch
            $this->assertSame(3, $e->line_number);
        }
    }

    /**
     * @dataProvider parseErrors
     */
    public function test_parse_errors(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->createParser($code)->parse();
    }

    public static function parseErrors(): array
    {
        return [
            'try without catch' => ['try { } echo 1;', "Expected 'catch' but found 'echo'"],
            'catch without a variable' => ['try { } catch { }', "Expected '(' but found '{'"],
            'catch with a non-variable' => ['try { } catch (1) { }', "Expected a \$variable but found '1'"],
            'catch without try' => ['catch ($e) { }', "Unexpected 'catch'"],
            'syntax errors are not caught' => ['try { echo ; } catch ($e) { }', "Unexpected ';'"],
        ];
    }

    public function test_code_gen_for_try_catch()
    {
        $this->assertEquals(
            "TRY CATCH_0\nPUSH_STR \"x\"\nCALL_BUILTIN error 1\nPOP\nEND_TRY\nJMP ENDTRY_0\n"
            ."LABEL CATCH_0\nSTORE 0\nLOAD 0\nPUSH_STR \"message\"\nINDEX_GET\nPRINT\nLABEL ENDTRY_0",
            $this->generateCode('try { error("x"); } catch ($e) { echo $e["message"]; }')
        );
    }

    public function test_code_gen_leaves_each_try_when_breaking_out_of_a_loop()
    {
        $code = $this->generateCode('try { while (true) { try { try { break; } catch ($a) { } } catch ($b) { } } } catch ($c) { }');

        // Two tries inside the loop are left by the break; the one around the loop is not
        $this->assertStringContainsString("TRY CATCH_3\nEND_TRY\nEND_TRY\nJMP ENDWHILE_1", $code);
    }
}
