<?php

namespace GazLang\Tests;

use GazLang\CodeGenerator\CodeGenerator;
use GazLang\VM\VM;

/**
 * The VM beyond the shared test helpers, which already run every executeCode() snippet and
 * tests/gaz program on it
 */
class VMTest extends GazLangTestCase
{
    public function test_deep_recursion_runs_in_process_without_growing_the_php_stack()
    {
        // The VM's calls are frames in an array, so this doesn't recurse on the PHP stack, which
        // can segfault this deep while pcov is loaded
        $program = (new CodeGenerator($this->createParser(
            'fn down($n) { if ($n == 0) { return "bottom"; } return down($n - 1); } echo down(9000);'
        )->parse()))->compile();

        ob_start();
        (new VM($program))->run();
        $this->assertSame("bottom\n", ob_get_clean());
    }

    public function test_call_depth_limit_on_the_vm_can_be_caught()
    {
        $program = (new CodeGenerator($this->createParser(
            'fn forever() { return forever(); } try { forever(); } catch ($e) { echo $e.message; }'
        )->parse()))->compile();

        ob_start();
        (new VM($program))->run();
        $this->assertSame("Maximum call depth of 10000 exceeded calling forever\n", ob_get_clean());
    }

    public function test_cli_runs_piped_programs_with_arguments()
    {
        exec(sprintf(
            'echo %s | %s %s -- a b 2>&1',
            escapeshellarg('echo args(); echo 7 / 2; error("done");'),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::ROOT.'/bin/gazlang-php')
        ), $output, $exit_code);

        $this->assertSame(['["a", "b"]', '3.5', 'Error: done'], $output);
        $this->assertSame(1, $exit_code);
    }
}
