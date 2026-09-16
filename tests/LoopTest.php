<?php

namespace GazLang\Tests;

use Exception;

class LoopTest extends GazLangTestCase
{
    public function test_while_loop()
    {
        $this->assertEquals("0\n1\n2\n", $this->executeCode(
            '$i = 0; while ($i < 3) { echo $i; $i = $i + 1; }'
        ));
    }

    public function test_while_with_false_condition_never_runs()
    {
        $this->assertEquals("done\n", $this->executeCode('while (0) { echo "nope"; } echo "done";'));
    }

    public function test_for_loop_runs_step_after_body()
    {
        $this->assertEquals("0\n2\n4\n6\n8\n", $this->executeCode(
            'for ($i = 0; $i < 4; $i = $i + 1) { echo $i * 2; } echo $i * 2;'
        ));
    }

    public function test_nested_loops_with_if()
    {
        // Sum of i*j over 1..3, skipping the diagonal: 36 - (1 + 4 + 9)
        $this->assertEquals("22\n", $this->executeCode(<<<'CODE'
            $sum = 0;
            for ($i = 1; $i <= 3; $i = $i + 1) {
                $j = 1;
                while ($j <= 3) {
                    if ($i != $j) { $sum = $sum + $i * $j; }
                    $j = $j + 1;
                }
            }
            echo $sum;
            CODE));
    }

    public function test_for_requires_all_three_clauses()
    {
        $this->expectException(Exception::class);
        $this->createParser('for ($i = 0; $i < 3) { echo $i; }')->parse();
    }

    public function test_code_gen_for_for()
    {
        $this->assertEquals(
            "PUSH 0\nSTORE 0\nLOAD 0\nPOP\n"
            ."LABEL WHILE_0\nLOAD 0\nPUSH 2\nLT\nJZ ENDWHILE_0\n"
            ."LOAD 0\nPRINT\nLABEL CONTINUE_0\nLOAD 0\nPUSH 1\nADD_OR_CONCAT\nSTORE 0\nLOAD 0\nPOP\n"
            ."JMP WHILE_0\nLABEL ENDWHILE_0",
            $this->generateCode('for ($i = 0; $i < 2; $i = $i + 1) { echo $i; }')
        );
    }

    public function test_code_gen_allows_reading_a_variable_assigned_later_in_the_loop()
    {
        $code = '$i = 0; while ($i < 2) { if ($i > 0) { echo $p; } $p = $i; $i = $i + 1; }';

        $this->assertEquals("0\n", $this->executeCode($code));
        $this->assertStringContainsString('LOAD 1', $this->generateCode($code));
    }

    public function test_break_leaves_the_loop()
    {
        $this->assertEquals("0\n1\nafter 2\n", $this->executeCode(
            '$i = 0; while (true) { if ($i === 2) { break; } echo $i; $i = $i + 1; } echo "after " + $i;'
        ));
    }

    public function test_continue_in_for_still_runs_the_step()
    {
        $this->assertEquals("1\n3\n", $this->executeCode(
            'for ($i = 0; $i < 4; $i = $i + 1) { if ($i === 0 || $i === 2) { continue; } echo $i; }'
        ));
    }

    public function test_continue_in_while_rechecks_the_condition()
    {
        $this->assertEquals("1\n3\n", $this->executeCode(
            '$i = 0; while ($i < 3) { $i = $i + 1; if ($i === 2) { continue; } echo $i; }'
        ));
    }

    public function test_break_and_continue_only_affect_the_innermost_loop()
    {
        $this->assertEquals("0 0\n1 0\n2 0\n", $this->executeCode(<<<'CODE'
            for ($i = 0; $i < 3; $i = $i + 1) {
                for ($j = 0; $j < 3; $j = $j + 1) {
                    if ($j === 1) { break; }
                    if ($i === 5) { continue; }
                    echo $i + " " + $j;
                }
            }
            CODE));
    }

    public function test_break_outside_a_loop_is_a_parse_error()
    {
        $this->expectExceptionMessage('Cannot use break outside of a loop');
        $this->createParser('if (1) { break; }')->parse();
    }

    public function test_continue_after_a_loop_is_a_parse_error()
    {
        $this->expectExceptionMessage('Cannot use continue outside of a loop');
        $this->createParser('while (0) { } continue;')->parse();
    }

    public function test_code_gen_for_break_and_continue()
    {
        $this->assertEquals(
            "LABEL WHILE_0\nPUSH 1\nJZ ENDWHILE_0\nJMP ENDWHILE_0\nJMP WHILE_0\nJMP WHILE_0\nLABEL ENDWHILE_0",
            $this->generateCode('while (1) { break; continue; }')
        );
        $this->assertStringContainsString(
            "JZ ENDWHILE_0\nJMP CONTINUE_0\nLABEL CONTINUE_0",
            $this->generateCode('for ($i = 0; $i < 3; $i = $i + 1) { continue; }')
        );
    }

    public function test_code_gen_for_while()
    {
        $this->assertEquals(
            "PUSH 1\nSTORE 0\nLOAD 0\nPOP\n"
            ."LABEL WHILE_0\nLOAD 0\nJZ ENDWHILE_0\nPUSH 0\nSTORE 0\nLOAD 0\nPOP\nJMP WHILE_0\nLABEL ENDWHILE_0",
            $this->generateCode('$x = 1; while ($x) { $x = 0; }')
        );
    }
}
