<?php

namespace GazLang\Tests;

class FinallyTest extends GazLangTestCase
{
    public function test_finally_runs_after_the_try_block_and_after_a_catch()
    {
        $this->assertEquals("body\nfinally 1\nbody\ncaught boom\nfinally 2\nafter\n", $this->executeCode(<<<'CODE'
            try { echo "body"; } catch ($e) { echo "not run"; } finally { echo "finally 1"; }
            try { echo "body"; error("boom"); } catch ($e) { echo "caught " .. $e.message; } finally { echo "finally 2"; }
            echo "after";
            CODE));
    }

    public function test_finally_runs_when_an_error_passes_through_and_the_error_carries_on()
    {
        $this->assertEquals("inner\nfinally\ncaught first on line 2\n", $this->executeCode(<<<'CODE'
            try {
                try { echo "inner"; error("first"); } finally { echo "finally"; }
                echo "not run";
            } catch ($e) {
                echo "caught {$e.message} on line {$e.line}";
            }
            CODE));
    }

    /**
     * @dataProvider backends
     */
    public function test_uncaught_errors_run_finally_and_exit_does_not(bool $vm)
    {
        $file = tempnam(sys_get_temp_dir(), 'gaz');
        try {
            file_put_contents($file, 'try { echo "body"; $x = 1 / 0; } finally { echo "finally"; }');
            $this->assertSame(["body\nfinally\nError: Division by zero at {$file}:1\n", 1], $this->runProgram($file, [], $vm));

            file_put_contents($file, 'try { echo "body"; exit(3); } finally { echo "not run"; }');
            $this->assertSame(["body\n", 3], $this->runProgram($file, [], $vm));
        } finally {
            unlink($file);
        }
    }

    public static function backends(): array
    {
        return ['interpreter' => [false], 'vm' => [true]];
    }

    public function test_an_error_in_a_catch_block_runs_finally()
    {
        $this->assertEquals("finally\nsecond\n", $this->executeCode(<<<'CODE'
            try {
                try { error("first"); } catch ($e) { error("second"); } finally { echo "finally"; }
            } catch ($e) {
                echo $e.message;
            }
            CODE));
    }

    public function test_return_runs_finally_with_the_value_already_worked_out()
    {
        $this->assertEquals("finally\n1\nhelper\ncatch finally\n7\n", $this->executeCode(<<<'CODE'
            fn f() {
                $x = 1;
                try { return $x; } finally { $x = 2; echo "finally"; }
            }
            echo f();
            fn helper() { echo "helper"; return 99; }
            fn g() {
                try { error("x"); } catch ($e) { return 7; } finally { helper(); echo "catch finally"; }
            }
            echo g();
            CODE));
    }

    public function test_nested_finally_blocks_run_innermost_first()
    {
        $this->assertEquals("inner\nouter\ndone\n", $this->executeCode(<<<'CODE'
            fn f() {
                try {
                    try { return "done"; } finally { echo "inner"; }
                } finally {
                    echo "outer";
                }
            }
            echo f();
            CODE));
    }

    public function test_break_and_continue_run_finally()
    {
        $this->assertEquals("[\"f0\", \"skip\", \"f1\", \"f2\"]\nstill caught here\n", $this->executeCode(<<<'CODE'
            $log = [];
            for ($i = 0; $i < 5; $i++) {
                try {
                    try {
                        if ($i == 1) { $log[] = "skip"; continue; }
                        if ($i == 3) { break; }
                    } finally {
                        $log[] = "f{$i}";
                    }
                } catch ($e) {
                    $log[] = "not run";
                }
                if ($i == 3) { $log[] = "not run"; }
            }
            echo slice($log, 0, 4);
            try { error("x"); } catch ($e) { echo "still caught here"; }
            CODE));
    }

    public function test_an_error_in_finally_replaces_the_error_or_return()
    {
        $this->assertEquals("from finally\nfrom finally too\n", $this->executeCode(<<<'CODE'
            try {
                try { error("lost"); } finally { error("from finally"); }
            } catch ($e) { echo $e.message; }
            fn f() {
                try { return 1; } finally { error("from finally too"); }
            }
            try { f(); } catch ($e) { echo $e.message; }
            CODE));
    }

    public function test_loops_tries_and_lambdas_inside_finally()
    {
        $this->assertEquals("0\ncaught inside\n5\n", $this->executeCode(<<<'CODE'
            try {
                echo "0";
            } finally {
                while (true) { break; }
                try { error("x"); } catch ($e) { echo "caught inside"; }
                $f = () -> { return 5; };
                echo $f();
            }
            CODE));
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
            'return in finally' => ["fn f() {\n try {} finally { return 1; }\n}", 'Cannot use return in finally on line 2'],
            'break in finally' => ['while (true) { try {} finally { break; } }', 'Cannot use break in finally on line 1'],
            'continue in finally' => ['while (true) { try {} finally { if (true) { continue; } } }', 'Cannot use continue in finally on line 1'],
            'return in a lambda in finally is fine' => ['try {} finally { $f = () -> { return 1; }; } echo ;', "Unexpected ';' on line 1"],
            'finally twice' => ['try {} finally {} finally {}', "Unexpected 'finally' on line 1"],
            'catch after finally' => ['try {} finally {} catch ($e) {}', "Unexpected 'catch' on line 1"],
        ];
    }

    public function test_code_gen_for_try_catch_finally()
    {
        $this->assertStringStartsWith(
            "TRY FINALLY_0\nTRY CATCH_0\nPUSH 1\nPRINT\nEND_TRY\nJMP ENDTRY_0\nLABEL CATCH_0\nCATCH_VALUE\nSTORE 0\nLABEL ENDTRY_0\n"
            ."END_TRY\nPUSH 2\nPRINT\nJMP ENDFINALLY_0\nLABEL FINALLY_0\nSTORE 1\nPUSH 2\nPRINT\nLOAD 1\nRETHROW\nLABEL ENDFINALLY_0\nHALT\n",
            $this->generateCode('try { echo 1; } catch ($e) {} finally { echo 2; }')
        );
    }

    public function test_code_gen_for_return_through_finally()
    {
        $this->assertStringContainsString(
            "LABEL FN_f\nTRY FINALLY_0\nPUSH 1\nSTORE 0\nEND_TRY\nPUSH 2\nPRINT\nLOAD 0\nRET\nEND_TRY\nPUSH 2\nPRINT\n",
            $this->generateCode('fn f() { try { return 1; } finally { echo 2; } } f();')
        );
    }
}
