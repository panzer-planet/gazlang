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

    public function test_code_gen_for_while()
    {
        $this->assertEquals(
            "PUSH 1\nSTORE 0\nLOAD 0\nPOP\n"
            ."LABEL WHILE_0\nLOAD 0\nJZ ENDWHILE_0\nPUSH 0\nSTORE 0\nLOAD 0\nPOP\nJMP WHILE_0\nLABEL ENDWHILE_0",
            $this->generateCode('$x = 1; while ($x) { $x = 0; }')
        );
    }
}
