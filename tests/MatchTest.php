<?php

namespace GazLang\Tests;

/**
 * match: how it parses, what it refuses, and the code it compiles to
 *
 * What it does at runtime is tested by tests/gaz/match/match_test.gaz.
 */
class MatchTest extends GazLangTestCase
{
    public function test_match_and_default_are_keywords_only_in_lowercase()
    {
        $this->assertSame(['MATCH', 'DEFAULT', 'IDENTIFIER', 'IDENTIFIER'], array_column($this->lex('match default MATCH Default'), 0));
    }

    public function test_a_variable_may_still_be_called_default()
    {
        // Sigils keep keywords and variable names apart, so $default and @match are fine
        $this->assertEquals("1\n", $this->executeCode('$default = 1; echo $default;'));
        $this->assertEquals("2\n", $this->executeCode('@match = 2; echo @match;'));
    }

    public function test_a_member_may_still_be_called_match()
    {
        // Member names can be any word, keywords included
        $this->assertEquals("1\n", $this->executeCode('kind C { fn match() { return 1; } } echo C().match();'));
    }

    /**
     * @dataProvider parseErrors
     */
    public function test_parse_errors(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->parse($code);
    }

    public static function parseErrors(): array
    {
        return [
            'no arms' => ['echo match (1) { };', 'A match needs at least one arm'],
            'default is not last' => ['echo match (1) { default => 1, 2 => 3 };', 'default must be the last arm of a match'],
            'two defaults' => ['echo match (1) { default => 1, default => 2 };', 'default must be the last arm of a match'],
            'a missing comma' => ['echo match (1) { 1 => 2 3 => 4 };', "Expected ',' but found '3'"],
            'a missing arrow' => ['echo match (1) { 1 2 };', "Expected '=>' but found '2'"],
            // Without parens there is no subject, so what follows match must be the body
            'a subject without parens' => ['echo match 1 { 1 => 2 };', "Expected '{' but found '1'"],
            'no arms and no subject' => ['echo match { };', 'A match needs at least one arm'],
            'no body' => ['echo match (1);', "Expected '{' but found ';'"],
            // A statement match ends at its }, so a ; after it starts an empty statement, as if (1) {}; does
            'a semicolon after a statement match' => ['match (1) { 1 => 2 };', "Unexpected ';'"],
            // Only a statement's arms are blocks, so { here starts a map literal
            'a block arm in an expression' => ['echo match (1) { 1 => { echo 2; } };', "Unexpected 'echo'"],
        ];
    }

    public function test_a_statement_match_needs_no_semicolon_but_an_expression_one_does()
    {
        $this->assertEquals("1\n", $this->executeCode('match (1) { 1 => { echo 1; } default => {} }'));
        $this->assertEquals("1\n", $this->executeCode('echo match (1) { 1 => 1 };'));
    }

    public function test_code_gen_tests_every_arm_then_jumps_to_its_body()
    {
        // The subject is stored once; EQUALS then NOT then JZ jumps when the two are equal,
        // and the default arm's JMP is the last test, so nothing after it is unreachable
        $this->assertEquals(
            "PUSH 2\nSTORE 0\n"
            ."LOAD 0\nPUSH 1\nEQUALS\nNOT\nJZ MATCH_ARM_0_0\n"
            ."LOAD 0\nPUSH 2\nEQUALS\nNOT\nJZ MATCH_ARM_0_1\n"
            ."JMP MATCH_ARM_0_2\n"
            ."LABEL MATCH_ARM_0_0\nPUSH \"a\"\nJMP MATCH_END_0\n"
            ."LABEL MATCH_ARM_0_1\nPUSH \"b\"\nJMP MATCH_END_0\n"
            ."LABEL MATCH_ARM_0_2\nPUSH \"c\"\nJMP MATCH_END_0\n"
            ."LABEL MATCH_END_0\nPRINT",
            $this->generateCode('echo match (2) { 1 => "a", 2 => "b", default => "c" };')
        );
    }

    public function test_code_gen_falls_past_the_last_test_into_no_match()
    {
        $this->assertEquals(
            "PUSH 2\nSTORE 0\nLOAD 0\nPUSH 1\nEQUALS\nNOT\nJZ MATCH_ARM_0_0\n"
            ."LOAD 0\nNO_MATCH\n"
            ."LABEL MATCH_ARM_0_0\nPUSH \"a\"\nJMP MATCH_END_0\nLABEL MATCH_END_0\nPRINT",
            $this->generateCode('echo match (2) { 1 => "a" };')
        );
    }

    public function test_code_gen_of_a_subject_less_match_tests_each_condition_directly()
    {
        // No subject means no hidden variable and no EQUALS: the same NOT then JZ jumps when
        // the condition is true, and falling past every test is NO_CONDITION, which pops nothing
        $this->assertEquals(
            "PUSH false\nNOT\nJZ MATCH_ARM_0_0\n"
            ."PUSH true\nNOT\nJZ MATCH_ARM_0_1\n"
            ."NO_CONDITION\n"
            ."LABEL MATCH_ARM_0_0\nPUSH \"a\"\nJMP MATCH_END_0\n"
            ."LABEL MATCH_ARM_0_1\nPUSH \"b\"\nJMP MATCH_END_0\n"
            ."LABEL MATCH_END_0\nPRINT",
            $this->generateCode('echo match { false => "a", true => "b" };')
        );
    }

    public function test_code_gen_gives_a_block_arm_a_value_so_every_path_leaves_one()
    {
        // The statement's POP pops something whichever arm ran
        $this->assertEquals(
            "PUSH 1\nSTORE 0\nLOAD 0\nPUSH 1\nEQUALS\nNOT\nJZ MATCH_ARM_0_0\nJMP MATCH_ARM_0_1\n"
            ."LABEL MATCH_ARM_0_0\nPUSH 2\nPRINT\nPUSH null\nJMP MATCH_END_0\n"
            ."LABEL MATCH_ARM_0_1\nPUSH null\nJMP MATCH_END_0\n"
            ."LABEL MATCH_END_0\nPOP",
            $this->generateCode('match (1) { 1 => { echo 2; } default => {} }')
        );
    }

    public function test_a_lambda_captures_variables_an_arm_value_uses()
    {
        // A match's arms are the first children two arrays deep, so a walker that unrolled a
        // fixed number of levels found an arm's body but silently dropped its values
        $this->assertEquals(
            "hit\n",
            $this->executeCode('fn make($t) { return $x -> match ($x) { $t => "hit", default => "miss" }; } echo make(7)(7);')
        );
    }

    public function test_a_lambda_inside_an_arm_value_contributes_its_captures()
    {
        $this->assertEquals(
            "hit\n",
            $this->executeCode('fn make($t) { return $x -> match ($x) { (() -> $t)() => "hit", default => "miss" }; } echo make(7)(7);')
        );
    }

    public function test_a_plain_assignment_in_an_arm_value_is_local_to_the_call()
    {
        // $n is assigned in an arm value, so it is local to each call rather than captured
        $this->assertEquals(
            "Undefined variable: \$n\n",
            $this->executeCode('$n = 1; $f = $x -> match ($x) { ($n = $n + 1) => "a", default => "b" };'
                .'try { $f(1); } catch (Error $e) { echo $e.message; }')
        );
    }

    public function test_nested_matches_do_not_share_hidden_variables_or_labels()
    {
        $code = $this->generateCode('echo match (1) { 1 => match (2) { 2 => "in" }, default => "out" };');
        $this->assertStringContainsString('STORE 0', $code);
        $this->assertStringContainsString('STORE 1', $code);
        $this->assertStringContainsString('MATCH_END_0', $code);
        $this->assertStringContainsString('MATCH_END_1', $code);
    }

    public function test_a_subject_less_match_tests_conditions_for_truth_not_equality()
    {
        // == is strict, so match (true) never matches a truthy non-bool; a condition does
        $this->assertEquals("truthy\n", $this->executeCode('echo match { 1 => "truthy", default => "no" };'));
        $this->assertEquals("no\n", $this->executeCode('echo match (true) { 1 => "truthy", default => "no" };'));
        $this->assertEquals("empty\n", $this->executeCode('echo match { "", 0, "x" => "empty", default => "no" };'));
    }

    public function test_a_subject_less_match_that_matches_nothing_names_no_value()
    {
        // There is nothing to name: every arm simply was not true
        $this->assertEquals("No arm matched\n", $this->executeCode('try { echo match { false => 1 }; } catch (Error $e) { echo $e.message; }'));
    }

    public function test_a_subject_less_match_may_be_a_statement_with_block_arms()
    {
        $this->assertEquals("b\n", $this->executeCode('$c = 2; match { $c == 1 => { echo "a"; } $c == 2 => { echo "b"; } default => {} }'));
    }

    public function test_a_subject_less_match_evaluates_conditions_in_order_and_stops()
    {
        $this->assertEquals(
            "b [1, 2]\n",
            $this->executeCode('@ran = []; fn loud($v, $r) { @ran[] = $v; return $r; }'
                .'echo match { loud(1, false) => "a", loud(2, true) => "b", loud(3, true) => "c" } .. " " .. @ran;')
        );
    }
}
