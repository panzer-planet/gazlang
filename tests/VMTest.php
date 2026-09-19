<?php

namespace GazLang\Tests;

/**
 * The VM beyond the shared test helpers, which already run every executeCode() snippet and
 * tests/gaz program on it
 */
class VMTest extends GazLangTestCase
{
    public function test_deep_recursion_runs()
    {
        $this->assertSame("bottom\n", $this->executeCode('fn down($n) { if ($n == 0) { return "bottom"; } return down($n - 1); } echo down(9000);'));
    }

    public function test_call_depth_limit_on_the_vm_can_be_caught()
    {
        $this->assertSame(
            "Maximum call depth of 10000 exceeded calling forever\n",
            $this->executeCode('fn forever() { return forever(); } try { forever(); } catch ($e) { echo $e.message; }')
        );
    }

    public function test_cli_runs_piped_programs_with_arguments()
    {
        [$output, $exit_code] = self::cli(['--', 'a', 'b'], 'echo args(); echo 7 / 2; error("done");');

        $this->assertSame(['["a", "b"]', '3.5', 'Error: done'], $output);
        $this->assertSame(1, $exit_code);
    }
}
