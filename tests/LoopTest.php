<?php

namespace GazLang\Tests;

use Exception;
use GazLang\AST\LoopControlAST;
use GazLang\CodeGenerator\CodeGenerator;
use GazLang\Lexer\Token;

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
        // A continue that leaked outwards would print nothing; a leaking break would stop after "0 1"
        $this->assertEquals("0 1\n1 1\n2 1\n", $this->executeCode(<<<'CODE'
            for ($i = 0; $i < 3; $i = $i + 1) {
                for ($j = 0; $j < 3; $j = $j + 1) {
                    if ($j === 0) { continue; }
                    if ($j === 2) { break; }
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
        $this->assertEquals(
            "LABEL WHILE_0\nPUSH 1\nJZ ENDWHILE_0\n"
            ."LABEL WHILE_1\nPUSH 2\nJZ ENDWHILE_1\nJMP ENDWHILE_1\nJMP WHILE_1\nLABEL ENDWHILE_1\n"
            ."JMP ENDWHILE_0\nJMP WHILE_0\nLABEL ENDWHILE_0",
            $this->generateCode('while (1) { while (2) { break; } break; }')
        );
        $this->assertStringContainsString(
            "JZ ENDWHILE_0\nJMP CONTINUE_0\nLABEL CONTINUE_0",
            $this->generateCode('for ($i = 0; $i < 3; $i = $i + 1) { continue; }')
        );
    }

    public function test_code_gen_rejects_loop_control_outside_a_loop()
    {
        // The parser already refuses this; the generator must not emit a JMP with no target if one slips through
        $this->expectExceptionMessage('Cannot use break outside of a loop');
        (new CodeGenerator(new LoopControlAST(new Token(Token::BREAK, 'break'))))->generate();
    }

    public function test_foreach_over_a_non_array_is_an_error()
    {
        $this->expectExceptionMessage('foreach expects an array, got string on line 2');
        $this->executeCode("\$s = \"abc\";\nforeach (\$s as \$c) { }");
    }

    /**
     * @dataProvider invalidForeach
     */
    public function test_foreach_parse_errors(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->createParser($code)->parse();
    }

    public static function invalidForeach(): array
    {
        return [
            'missing as' => ['foreach ([1] $v) { }', "Expected 'as' but found '\$v'"],
            'value is not a variable' => ['foreach ([1] as 1) { }', "Expected a \$variable but found '1'"],
            'key is not a variable' => ['foreach ([1] as $k => 2) { }', "Expected a \$variable but found '2'"],
            'index as the value' => ['foreach ([1] as $a[0]) { }', "Expected ')' but found '['"],
            'break outside the body' => ['foreach ([1] as $v) { } break;', 'Cannot use break outside of a loop'],
        ];
    }

    public function test_code_gen_lowers_foreach_to_a_while_loop_over_keys()
    {
        $code = $this->generateCode('foreach ([7] as $k => $v) { continue; }');

        // Slots: $#array 0, $#keys 1, $#count 2, $#i 3, $k 4, $v 5
        $this->assertStringContainsString("CALL_BUILTIN keys 1\nSTORE 1", $code);
        // The count is taken once, before the loop, not on every iteration
        $this->assertStringContainsString("LOAD 1\nCALL_BUILTIN len 1\nSTORE 2", $code);
        $this->assertSame(1, substr_count($code, 'CALL_BUILTIN len'));
        $this->assertStringContainsString("LABEL WHILE_0\nLOAD 3\nLOAD 2\nLT\nJZ ENDWHILE_0", $code);
        // $k = $#keys[$#i]; $v = $#array[$#keys[$#i]]; continue jumps to the step
        $this->assertStringContainsString("LOAD 1\nLOAD 3\nINDEX_GET\nSTORE 4\nLOAD 4\nPOP\nLOAD 0\nLOAD 1\nLOAD 3\nINDEX_GET\nINDEX_GET\nSTORE 5", $code);
        $this->assertStringContainsString("JMP CONTINUE_0\nLABEL CONTINUE_0\nLOAD 3\nPUSH 1\nADD_OR_CONCAT\nSTORE 3", $code);
    }

    public function test_code_gen_gives_nested_foreach_loops_their_own_hidden_variables()
    {
        $code = $this->generateCode('foreach ([[1]] as $row) { foreach ($row as $cell) { } }');

        // Two separate keys() results stored in two separate slots
        preg_match_all('/CALL_BUILTIN keys 1\nSTORE (\d+)/', $code, $slots);
        $this->assertCount(2, array_unique($slots[1]));
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
