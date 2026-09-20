<?php

namespace GazLang\Tests;

/**
 * const NAME = value; at the top level and in a kind: the parser works the value out, and a use is that value
 */
class ConstTest extends GazLangTestCase
{
    public function test_a_constant_is_its_value_wherever_it_is_used()
    {
        $this->assertSame(
            "3 3.5 text true null\n6\n[3, 4]\nhit\n",
            $this->executeCode('const N = 3; const F = 3.5; const S = "text"; const B = true; const NOTHING = null;
                echo N .. " " .. F .. " " .. S .. " " .. B .. " " .. NOTHING;
                fn twice($x = N) { return $x * 2; } echo twice();
                echo [N, N + 1];
                echo match (3) { N => "hit", default => "miss" };')
        );
    }

    public function test_a_value_is_worked_out_with_the_operators_a_program_has()
    {
        $this->assertSame(
            "7 1.5 ab1 true -3 -4 false 6 [1, [2, \"x\"]] {\"k\" => 3, 4 => true} big 5 9\n",
            $this->executeCode('const A = 1 + 2 * 3; const B = 3 / 2; const C = "a" .. "b" .. 1; const D = 1 < 2 && "x" == "x";
                const E = -3; const G = ~3; const H = !true; const I = 5 % 3 | 2 & 6 ^ 4 << 1 >> 1;
                const L = [1, [2, "x"]]; const M = {"k" => 1 + 2, 2 * 2 => true}; const T = 10 > 9 ? "big" : "small";
                const Q = null ?? 5; const P = 1 <=> 0 == 1 ? 9 : 8;
                echo A .. " " .. B .. " " .. C .. " " .. D .. " " .. E .. " " .. G .. " " .. H .. " " .. I .. " " .. L .. " " .. M .. " " .. T .. " " .. Q .. " " .. P;')
        );
    }

    public function test_constants_can_use_each_other_in_any_order_and_be_used_before_they_are_declared()
    {
        $this->assertSame(
            "12\n12\n",
            $this->executeCode('echo AREA; fn area() { return AREA; } echo area(); const AREA = WIDTH * HEIGHT; const HEIGHT = WIDTH + 1; const WIDTH = 3;')
        );
    }

    public function test_the_side_of_an_operator_that_is_not_needed_is_not_worked_out()
    {
        $this->assertSame("false true 1 no\n", $this->executeCode('const A = false && 1 / 0; const B = true || 1 / 0; const C = 1 ?? 1 / 0; const D = false ? 1 / 0 : "no"; echo A .. " " .. B .. " " .. C .. " " .. D;'));
    }

    public function test_a_constant_holding_a_list_or_map_cannot_be_changed_through_a_copy()
    {
        $this->assertSame(
            "[1, 2, 3] [1, 2]\n{\"a\" => 1, \"b\" => 2} {\"a\" => 1}\n2 1\n",
            $this->executeCode('const L = [1, 2]; const M = {"a" => 1};
                $l = L; $l[] = 3; echo $l .. " " .. L;
                $m = M; $m["b"] = 2; echo $m .. " " .. M;
                echo L[1] .. " " .. M["a"];')
        );
    }

    public function test_a_kind_has_constants_reached_by_its_name_or_by_hash_inside_it()
    {
        $this->assertSame(
            "EOF\n[\"EOF\", \"EOF!\", 2]\ntrue EOF EOF\n[\"EOF\", \"EOF!\", 2] EOF 4\n",
            $this->executeCode('const TWO = 2;
                echo Token::EOF; echo Token::KINDS;
                kind Token {
                    const EOF = "EOF";
                    const KINDS = [#EOF, Token::EOF .. "!", TWO];
                    #type = #EOF;
                    fn is_eof() { return #type == #EOF; }
                    fn later() { return () -> #EOF; }
                    fn describe($type = #EOF) { return $type; }
                }
                $t = Token(); echo $t.is_eof() .. " " .. $t.later()() .. " " .. $t.describe();
                kind Special extends Token { const FOUR = TWO * 2; fn kinds() { return #KINDS; } }
                echo Special().kinds() .. " " .. Special::EOF .. " " .. Special::FOUR;')
        );
    }

    public function test_a_constant_is_reached_by_name_and_not_through_a_value()
    {
        // Working it out from a value would need a lookup when the program runs; a use is a PUSH
        foreach (['$c = Token; echo $c.EOF;' => 'Cannot use . on kind', '$t = Token(); echo $t.EOF;' => 'Token has no member EOF'] as $code => $message) {
            try {
                $this->executeCode('kind Token { const EOF = "EOF"; } '.$code);
                $this->fail("Expected an error for {$code}");
            } catch (ProgramError $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_a_use_compiles_to_the_value()
    {
        $code = $this->generateCode('const LIMIT = 10 * 2; kind A { const NAMES = ["a", "b"]; fn f() { return #NAMES; } } echo LIMIT; echo A::NAMES;');

        $this->assertStringContainsString("PUSH 20\n", $code);
        $this->assertSame(2, substr_count($code, 'PUSH ["a", "b"]'));
        $this->assertStringNotContainsString('LIMIT', $code);
    }

    public function test_the_left_of_a_coalesce_can_be_a_constant()
    {
        $this->assertSame("1 x\n", $this->executeCode('const A = 1; kind C { const N = null; fn f() { return #N ?? "x"; } } echo (A ?? 2) .. " " .. C().f();'));
    }

    /**
     * An expression for every operator and kind of value a constant can have; tests/parser_corpus/constant_values.gaz
     * is made from it, so the self-hosted parser, which works values out with GazLang's own operators, is checked on it too
     *
     * @return string[]
     */
    public static function expressions(): array
    {
        return [
            '1 + 2', '7 - 9', '3 * 4', '7 / 2', '6 / 2', '-7 % 3', '7 % -3', '1.5 + 1', '2 * 0.25', '"a" .. 1 .. 2.0 .. true .. null .. [1] .. {"k" => 1}',
            '1 == 1.0', '"1" == 1', '[1] == [1.0]', '{"a" => 1, "b" => 2} == {"b" => 2, "a" => 1}', '[] == {}', '1 != 2', 'null == false',
            '1 <=> 2', '"b" <=> "a"', '2.0 <=> 2', '1 < 2', '"10" < "9"', '2 <= 2', '3 > 2.5', '2 >= 3',
            '6 & 3', '6 | 3', '6 ^ 3', '1 << 62', '1 << 63', '-1 << 1', '-8 >> 1', '~0', '~-1',
            '-5', '-2.5', '-0.0', '- -3', '!0', '!""', '!"0"', '![]', '!{}', '![0]', '!null', '!!1.5',
            '1 && 2', '0 && 2', '"" || "x"', '0 || null', 'null ?? 1', '0 ?? 1', 'false ?? 1', 'null ?? null ?? 3',
            '1 ? "a" : "b"', '0.0 ? "a" : "b"', '[] ? "a" : "b"', '1 ? 2 ? "x" : "y" : "z"',
            '[1, [2.5, "s"], {"k" => [true, null]}]', '{"a" => 1, 1 => "one", "1" => "string one", "a" => 2}',
            '9223372036854775807', '-9223372036854775807 - 1', '9007199254740993 == 9007199254740992.0', '0.1 + 0.2',
            '1 + 2 * 3 - 4 / 5', '"n = " .. 1 + 2', '"x" .. 6 & 3', '1 << 2 + 1', '6 & 3 == 2',
            '[...[1, 2], 3, ...[]]', '[...[[1]], ...["a" .. 1], ...[] ? [1] : [2]]',
        ];
    }

    /**
     * What tests/parser_corpus/constant_values.gaz holds: a constant per expression, then all of them
     */
    public static function constantValuesSource(): string
    {
        $lines = [
            '// Made from ConstTest::expressions(): every operator and kind of value a constant can have. The values are',
            '// in the tree, on each use, so the two parsers are compared on what they work out.',
        ];
        $names = [];
        foreach (self::expressions() as $i => $expression) {
            $lines[] = "const C{$i} = {$expression};";
            $names[] = "C{$i}";
        }
        $lines[] = 'echo ['.implode(', ', $names).'];';

        return implode("\n", $lines)."\n";
    }

    public function test_the_ports_corpus_file_is_made_from_the_table()
    {
        $this->assertSame(self::constantValuesSource(), file_get_contents(self::ROOT.'/tests/parser_corpus/constant_values.gaz'));
    }

    public static function errors(): array
    {
        return [
            'a variable' => ['const A = $x;', "A constant's value can only use literals, operators and other constants on line 1"],
            'a call' => ["const A = 1;\nconst B = 2 + len([A]);", "A constant's value can only use literals, operators and other constants on line 2"],
            'an index' => ['const L = [1]; const A = L[0];', "A constant's value can only use literals, operators and other constants on line 1"],
            'a function' => ['const A = len;', 'len is a function, not a constant on line 1'],
            'a kind' => ['kind K {} const A = K;', 'K is a kind, not a constant on line 1'],
            'a field' => ['kind K { #f = 1; const A = #f; }', "A constant's value can only use literals, operators and other constants on line 1"],
            'a lambda' => ['const A = $x -> 1;', "A constant's value can only use literals, operators and other constants on line 1"],
            'an undefined name' => ['const A = MISSING;', 'Undefined constant: MISSING on line 1'],
            'division by zero' => ["const A = 1;\nconst B = A /\n 0;", 'Division by zero on line 2'],
            'an overflow' => ['const A = 9223372036854775807 + 1;', 'Integer overflow on line 1'],
            'a bad operand' => ['const A = "a" + 1;', 'Cannot use + on string on line 1'],
            'a float as a key' => ['const M = {1.5 => 1};', 'on line 1'],
            'itself' => ['const A = A + 1;', 'Constant A depends on itself: A uses A on line 1'],
            'a cycle' => ["const A = B;\nconst B = C * 2;\nconst C = A;", 'Constant A depends on itself: A uses B uses C uses A on line 3'],
            'a cycle through a kind' => ['kind K { const A = K::B; const B = #A; }', 'Constant K::A depends on itself: K::A uses K::B uses K::A on line 1'],
            'declared twice' => ["const A = 1;\nconst A = 2;", 'Constant A is already declared on line 2'],
            'a function of that name' => ["const A = 1;\nfn A() {}", 'Constant A is already declared on line 2'],
            'a constant named like a function' => ["fn a() {}\nconst a = 1;", 'Function a is already declared on line 2'],
            'a constant named like a kind' => ['kind A {} const A = 1;', 'Kind A is already declared on line 1'],
            'a constant named like a builtin' => ['const len = 1;', 'len is a builtin function on line 1'],
            'no name' => ['const = 1;', "Expected a name but found '=' on line 1"],
            'a variable for a name' => ['const $a = 1;', "Expected a name but found '\$a' on line 1"],
            'no value' => ['const A;', "Expected '=' but found ';' on line 1"],
            'in a function' => ['fn f() { const A = 1; }', 'Constants can only be declared at the top level or in a kind on line 1'],
            'in a block' => ['if (1) { const A = 1; }', 'Constants can only be declared at the top level or in a kind on line 1'],
            'assigned to' => ['const A = 1; A = 2;', 'Can only use = on a variable, or an element or field of one on line 1'],
            'an element assigned to' => ['const A = [1]; A[0] = 2;', 'Can only use = on a variable, or an element or field of one on line 1'],
            'incremented' => ['const A = 1; A++;', 'Can only use ++ on a variable, or an element or field of one on line 1'],
            'called' => ["const A = 1;\necho A();", 'A is a constant, not a function on line 2'],
            'twice in a kind' => ['kind K { const A = 1; const A = 2; }', 'K already has a constant A: constants, fields and methods share names on line 1'],
            'a field then a constant' => ['kind K { #A; const A = 2; }', 'K already has a field #A: constants, fields and methods share names on line 1'],
            'a method then a constant' => ['kind K { fn A() {} const A = 2; }', 'K already has a method A: constants, fields and methods share names on line 1'],
            'a constant then a field' => ['kind K { const A = 2; #A; }', 'K already has a constant A: constants, fields and methods share names on line 1'],
            'a constant then a method' => ['kind K { const A = 2; fn A() {} }', 'K already has a constant A: constants, fields and methods share names on line 1'],
            'declared again by a child' => ["kind K { const A = 1; }\nkind L extends K {\n const A = 2; }", 'Constant A of L is already declared in K on line 3'],
            "a child's field" => ["kind K { const A = 1; }\nkind L extends K { #A; }", 'Field #A of L has the name of a constant of K: constants, fields and methods share names on line 2'],
            "a child's method" => ["kind K { const A = 1; }\nkind L extends K { fn A() {} }", 'Method L.A has the name of a constant of K: constants, fields and methods share names on line 2'],
            "a parent's field" => ["kind K { #A; }\nkind L extends K { const A = 1; }", 'Constant A of L has the name of a field of K: constants, fields and methods share names on line 2'],
            "a parent's method" => ["kind K { fn A() {} }\nkind L extends K { const A = 1; }", 'Constant A of L has the name of a method of K: constants, fields and methods share names on line 2'],
            'no such constant' => ["kind K { const A = 1; }\necho K::B;", 'Kind K has no constant B on line 2'],
            "a parent can't see a child's" => ["kind K { fn f() { return #B; } }\nkind L extends K { const B = 1; }", 'K has no member #B on line 1'],
            '#NAME assigned to' => ['kind K { const A = 1; fn f() { #A = 2; } }', 'Cannot change constant #A on line 1'],
            '#NAME element assigned to' => ['kind K { const A = [1]; fn f() { #A[0] = 2; } }', 'Cannot change constant #A on line 1'],
            '#NAME appended to' => ['kind K { const A = [1]; fn f() { #A[] = 2; } }', 'Cannot change constant #A on line 1'],
            '#NAME incremented' => ['kind K { const A = 1; fn f() { #A++; } }', 'Cannot change constant #A on line 1'],
            '#NAME compound assigned' => ['kind K { const A = "a"; fn f() { #A ..= "b"; } }', 'Cannot change constant #A on line 1'],
            '#NAME element deleted' => ['kind K { const A = [1]; fn f() { delete #A[0]; } }', 'Cannot change constant #A on line 1'],
            '#NAME in a pattern' => ['kind K { const A = 1; fn f() { [#A] = [2]; } }', 'Cannot change constant #A on line 1'],
            '#NAME called' => ['kind K { const A = 1; fn f() { return #A(); } }', '#A is a constant, not a method on line 1'],
            'a kind constant through a dot' => ["kind K { const A = 1; }\necho K.A;", "Cannot use . on a kind: write 'K::A', not 'K.A' on line 2"],
            'a kind constant through a dot in a value' => ["kind K { const A = 1; }\nconst B = K.A;", "Cannot use . on a kind: write 'K::A', not 'K.A' on line 2"],
            'Name::NAME called' => ["kind K { const A = 1; }\necho K::A();", 'K::A is a constant, not a function on line 2'],
            'Name::NAME assigned to' => ['kind K { const A = 1; } K::A = 2;', 'Cannot change constant K::A on line 1'],
            '#NAME of another kind in a value' => ['const A = #B;', 'Cannot use #B outside a method on line 1'],
            'a constant of a kind that has none in a value' => ['kind K {} const A = K::B;', 'Kind K has no constant B on line 1'],
            'a variable in the branch not taken' => ['const A = true ? 1 : $x;', "A constant's value can only use literals, operators and other constants on line 1"],
            'a call on the side not needed' => ["fn launch() {}\nconst A = true ||\n launch();", "A constant's value can only use literals, operators and other constants on line 3"],
            'an undefined name on the side not needed' => ['const A = false && MISSING;', 'Undefined constant: MISSING on line 1'],
            'a spread of a string' => ["const A = [\n...\"s\"];", 'Cannot spread string: only a list can be on line 2'],
            'a bad key before a bad value' => ['const M = {1.5 => 1 / 0};', 'Keys must be int or string, got float on line 1'],
            'a constant where a parent goes' => ["const K = 1;\nkind A extends K {}", 'K is a constant, not a kind on line 2'],
            'a constant where a catch type goes' => ['const K = 1; try { echo 1; } catch (K $e) { }', 'K is a constant, not a kind on line 1'],
            'a keyword in the wrong case' => ['Const A = 1;', "Expected ';' but found 'A' (keywords are lowercase: write 'const', not 'Const') on line 1"],
        ];
    }

    /**
     * @dataProvider errors
     */
    public function test_errors(string $code, string $message)
    {
        try {
            $this->executeCode($code);
            $this->fail('Expected an error');
        } catch (ProgramError $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_a_written_field_is_still_a_field_and_a_method_element_is_still_for_when_it_runs()
    {
        // Noting what is written under a #name must not change what a field or a method allows
        $this->assertSame("[1, 2]\n", $this->executeCode('kind K { #l = [1]; fn f() { #l[] = 2; return #l; } } echo K().f();'));
    }

    public function test_a_declared_constant_named_like_a_keyword_gets_no_hint()
    {
        try {
            $this->executeCode('const If = 1; echo If 2;');
            $this->fail('Expected an error');
        } catch (ProgramError $e) {
            $this->assertSame("Expected ';' but found '2' on line 1", $e->getMessage());
        }
    }

    /**
     * The parser works a value out with the functions a program runs on, but chooses between them
     * itself (Parser::fold()), as the code generator does: so every
     * operator is checked to give a constant what it gives a running program
     */
    public function test_a_constant_is_what_its_expression_gives_when_a_program_runs()
    {
        $expressions = self::expressions();
        $expected = $this->executeCode(implode("\n", array_map(fn (string $e) => "echo {$e};", $expressions)));
        $constants = implode("\n", array_map(fn (string $e, int $i) => "const C{$i} = {$e}; echo C{$i};", $expressions, array_keys($expressions)));

        $this->assertSame($expected, $this->executeCode($constants));
    }

    public function test_the_smallest_int_survives_being_written_to_bytecode()
    {
        // It has no literal of its own, so nothing but a constant puts it in a PUSH; the tests' VM side reads bytecode back
        $this->assertSame(
            "-9223372036854775808 [-9223372036854775808, {-9223372036854775808 => -9223372036854775808}]\n",
            $this->executeCode('const MIN = -9223372036854775807 - 1; const L = [MIN, {MIN => MIN}]; echo MIN .. " " .. L;')
        );
    }

    public function test_a_literal_made_of_constants_is_built_once()
    {
        $code = $this->generateCode('const A = 1; kind T { const B = "b"; } fn f() { return [A, T::B, {"k" => [A]}]; }');

        $this->assertStringContainsString('PUSH [1, "b", {"k" => [1]}]', $code);
        $this->assertStringNotContainsString('ARRAY_PUSH', $code);
    }

    public function test_included_files_share_constants()
    {
        [$output] = $this->runProgram('tests/fixtures/const/main.gaz');

        $this->assertSame("10 20 lib\n", $output);
    }
}
