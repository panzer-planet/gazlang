<?php

namespace GazLang\Tests;

use Exception;

class OperatorTest extends GazLangTestCase
{
    public function test_lexes_new_operators()
    {
        $expected = [
            'LESS_THAN', 'LESS_EQUALS', 'GREATER_THAN', 'GREATER_EQUALS',
            'NOT_EQUALS', 'NOT', 'AND', 'OR', 'EQUALS', 'ASSIGN',
        ];

        $this->assertSame($expected, array_column($this->lex('< <= > >= != ! && || == ='), 0));
    }

    public function test_lexes_bitwise_operators()
    {
        // Longest match: && before &=, and &= before &; <<= before << before <= before <
        $expected = [
            'BIT_AND', 'AND', 'BIT_AND_ASSIGN',
            'BIT_OR', 'OR', 'BIT_OR_ASSIGN',
            'BIT_XOR', 'BIT_XOR_ASSIGN', 'BIT_NOT',
            'SHIFT_LEFT', 'SHIFT_LEFT_ASSIGN', 'SHIFT_RIGHT', 'SHIFT_RIGHT_ASSIGN',
            'SPACESHIP', 'LESS_EQUALS',
        ];

        $this->assertSame($expected, array_column($this->lex('& && &= | || |= ^ ^= ~ << <<= >> >>= <=> <='), 0));
    }

    public function test_a_backtick_is_invalid()
    {
        $this->expectException(ProgramError::class);
        $this->lex('`');
    }

    /**
     * @dataProvider bitwisePrecedence
     */
    public function test_bitwise_precedence(string $code, string $expected)
    {
        $this->assertEquals($expected, $this->executeCode($code));
    }

    public static function bitwisePrecedence(): array
    {
        // relational -> concat -> bit_or -> bit_xor -> bit_and -> shift -> additive, as in
        // Rust and Python: the bitwise operators are above the comparisons, unlike C
        return [
            '& is above ==' => ['echo 6 & 3 == 2;', "true\n"],
            '| is below ^' => ['echo 1 | 2 ^ 3;', "1\n"],
            '^ is below &' => ['echo 1 ^ 3 & 2;', "3\n"],
            '& is above ..' => ['echo 12 & 10 .. "!";', "8!\n"],
            '& is above .. on the left too' => ['echo "x = " .. 12 & 10;', "x = 8\n"],
            '.. is below <<' => ['echo "n = " .. 1 << 4;', "n = 16\n"],
            '.. is still above ==' => ['echo "a" .. 1 == "a1";', "true\n"],
            '<< is below +' => ['echo 1 << 2 + 1;', "8\n"],
            '~ is a unary' => ['echo ~2 + 1;', "-2\n"],
            'shifts are left associative' => ['echo 256 >> 2 >> 2;', "16\n"],
            '& is left associative' => ['echo 7 & 6 & 4;', "4\n"],
        ];
    }

    public function test_lexes_power()
    {
        // Longest match: **= before ** before *=, and *** is ** then *
        $this->assertSame(['POWER', 'POWER_ASSIGN', 'MULTIPLY_ASSIGN', 'POWER', 'MULTIPLY'], array_column($this->lex('** **= *= ***'), 0));
    }

    /**
     * @dataProvider powers
     */
    public function test_power(string $code, string $expected)
    {
        $this->assertSame($expected, $this->executeCode($code));
    }

    public static function powers(): array
    {
        return [
            'an int to an int is an exact int' => ['echo 3 ** 4;', "81\n"],
            'the largest power of two' => ['echo 2 ** 62;', "4611686018427387904\n"],
            'the smallest int' => ['echo (-2) ** 63;', "-9223372036854775808\n"],
            'anything to 0 is 1' => ['echo [0 ** 0, 5 ** 0, 2.5 ** 0];', "[1, 1, 1.0]\n"],
            'a negative exponent gives a float' => ['echo 2 ** -2;', "0.25\n"],
            'a float base gives a float' => ['echo 1.5 ** 2;', "2.25\n"],
            'a whole float exponent' => ['echo 2 ** 3.0;', "8.0\n"],
            'a unary minus on the left is looser' => ['echo -2 ** 2;', "-4\n"],
            'a unary minus on the right is tighter' => ['echo 2 ** -1;', "0.5\n"],
            'right associative' => ['echo 2 ** 3 ** 2;', "512\n"],
            'tighter than *' => ['echo 2 * 3 ** 2;', "18\n"],
            'looser than a postfix' => ['$a = [3]; echo $a[0] ** 2;', "9\n"],
            '**=' => ['$x = 3; $x **= 3; echo $x;', "27\n"],
            'squaring a big base is not an overflow when the result fits' => ['echo (-1) ** 9223372036854775807;', "-1\n"],
            'sqrt' => ['echo [sqrt(2), sqrt(9), sqrt(0)];', "[1.4142135623730951, 3.0, 0.0]\n"],
        ];
    }

    /**
     * @dataProvider powerErrors
     */
    public function test_power_errors(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode($code);
    }

    public static function powerErrors(): array
    {
        return [
            'an int too big' => ['echo 2 ** 63;', 'Integer overflow on line 1'],
            'an int too small' => ['echo (-2) ** 65;', 'Integer overflow on line 1'],
            'a float too big' => ['echo 10.0 ** 309;', 'Float overflow on line 1'],
            'zero to a negative power' => ['echo 0 ** -1;', 'Division by zero on line 1'],
            'a fractional exponent' => ['echo 4 ** 0.5;', 'Exponent must be a whole number, got 0.5 on line 1'],
            'an exponent too big' => ['echo 1.0 ** 1e19;', 'Exponent is too large, got 1.0E+19 on line 1'],
            'a string' => ['echo "2" ** 2;', 'Cannot use ** on string on line 1'],
            'a bool' => ['echo 2 ** false;', 'Cannot use ** on bool on line 1'],
            '**= overflow' => ['$x = 10; $x **= 19;', 'Integer overflow on line 1'],
            'a constant' => ['const BIG = 2 ** 64;', 'Integer overflow on line 1'],
            'sqrt of a negative number' => ['sqrt(-4);', 'sqrt() expects a number that is not negative, got -4 on line 1'],
            'sqrt of a string' => ['sqrt("4");', 'sqrt() expects int or float, got string on line 1'],
        ];
    }

    /**
     * @dataProvider bitwiseErrors
     */
    public function test_bitwise_errors(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode($code);
    }

    public static function bitwiseErrors(): array
    {
        return [
            'a float on the left' => ['echo 1.5 & 1;', 'Cannot use & on float on line 1'],
            'a float on the right' => ['echo 1 & 1.5;', 'Cannot use & on float on line 1'],
            'a bool' => ['echo true | 1;', 'Cannot use | on bool on line 1'],
            'a string' => ['echo "a" ^ 1;', 'Cannot use ^ on string on line 1'],
            'a list' => ['echo [1] << 1;', 'Cannot use << on list on line 1'],
            'null' => ['echo null >> 1;', 'Cannot use >> on null on line 1'],
            '~ of a float' => ['echo ~1.5;', 'Cannot use ~ on float on line 1'],
            '~ of a bool' => ['echo ~true;', 'Cannot use ~ on bool on line 1'],
            'a shift count of 64' => ['echo 1 << 64;', 'Shift count must be between 0 and 63, got 64 on line 1'],
            'a negative shift count' => ['echo 1 >> -1;', 'Shift count must be between 0 and 63, got -1 on line 1'],
            'a float shift count' => ['echo 1 << 2.0;', 'Cannot use << on float on line 1'],
            '<<= a bad count' => ['$a = 1; $a <<= 64;', 'Shift count must be between 0 and 63, got 64 on line 1'],
        ];
    }

    public function test_code_gen_for_bitwise_operators()
    {
        // The compound form lowers to the operator, as every other one does
        $this->assertEquals("PUSH 12\nPUSH 10\nBIT_AND\nPRINT", $this->generateCode('echo 12 & 10;'));
        $this->assertEquals("PUSH 5\nBIT_NOT\nPRINT", $this->generateCode('echo ~5;'));
        $this->assertEquals(
            "PUSH 1\nSTORE 0\nLOAD 0\nPOP\nLOAD 0\nPUSH 4\nSHL\nSTORE 0\nLOAD 0\nPOP",
            $this->generateCode('$a = 1; $a <<= 4;')
        );
    }

    /**
     * @dataProvider floatErrors
     */
    public function test_float_errors(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode($code);
    }

    public static function floatErrors(): array
    {
        return [
            'float overflow' => ['echo 1e308 * 10;', 'Float overflow on line 1'],
            'float overflow by division' => ['echo 1e308 / 1e-10;', 'Float overflow on line 1'],
            'modulo with a float' => ['echo 5.5 % 2;', 'Cannot use % on float on line 1'],
            'division by float zero' => ['echo 1 / 0.0;', 'Division by zero on line 1'],
            'float map key' => ['echo {1.5 => 1};', 'Keys must be int or string, got float on line 1'],
            'float string position' => ['echo "abc"[1.0];', 'String positions must be int, got float on line 1'],
            'ordering a non-number string' => ['echo "abc" < 1.5;', 'Cannot use < on string and float on line 1'],
            'arithmetic on a string' => ['echo "1.5" + 1.5 * "2";', 'Cannot use * on string on line 1'],
            'to_int out of range' => ['to_int(1e19);', 'to_int() cannot convert 1.0E+19 on line 1'],
            'to_float of a non-number' => ['to_float("1.5x");', 'to_float() cannot convert "1.5x" on line 1'],
            'to_float of null' => ['to_float(null);', 'to_float() cannot convert null on line 1'],
            'intdiv by zero' => ['intdiv(7, 0);', 'Division by zero on line 1'],
            'intdiv with a float' => ['intdiv(7, 2.0);', 'intdiv() expects int, got float on line 1'],
            'intdiv overflow' => ['intdiv(-9223372036854775807 - 1, -1);', 'Integer overflow on line 1'],
            'abs overflow' => ['abs(-9223372036854775807 - 1);', 'Integer overflow on line 1'],
            'round of a string' => ['round("1.5");', 'round() expects int or float, got string on line 1'],
        ];
    }

    public function test_code_gen_pushes_floats_exactly()
    {
        $this->assertEquals("PUSH 0.1\nPUSH 1.0E+25\nMUL\nPRINT", $this->generateCode('echo 0.1 * 1e25;'));
    }

    /**
     * @dataProvider assignmentOperatorErrors
     */
    public function test_assignment_operator_errors(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode($code);
    }

    public static function assignmentOperatorErrors(): array
    {
        return [
            'undefined variable' => ['$x += 1;', 'Undefined variable: $x on line 1'],
            'undefined variable ++' => ['$x++;', 'Undefined variable: $x on line 1'],
            '++ on a string' => ['$s = "a"; $s++;', 'Cannot use ++ on string on line 1'],
            '-- on null' => ['$n = null; $n--;', 'Cannot use -- on null on line 1'],
            'missing key' => ['$a = {}; $a["x"] += 1;', 'Undefined key: "x" on line 1'],
            'missing key ++' => ['$a = {}; $a["x"]++;', 'Undefined key: "x" on line 1'],
            'missing key with a string, not "nullx"' => ['$a = {}; $a["n"] += "x";', 'Undefined key: "n" on line 1'],
            'missing index' => ['$a = []; $a[0] += 1;', 'Index out of range: 0 on line 1'],
            'element of a string' => ['$s = "ab"; $s[0]++;', 'Cannot use [] on string on line 1'],
            'missing key on the way' => ['$a = {}; $a["x"]["y"] += 1;', 'Undefined key: "x" on line 1'],
            'overflow' => ['$m = 9223372036854775807; $m++;', 'Integer overflow on line 1'],
            'modulo a float' => ['$f = 1.5; $f %= 2;', 'Cannot use % on float on line 1'],
        ];
    }

    /**
     * @dataProvider assignmentOperatorParseErrors
     */
    public function test_assignment_operator_parse_errors(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->parse($code);
    }

    public static function assignmentOperatorParseErrors(): array
    {
        return [
            'literal ++' => ['5++;', 'Can only use ++ on a variable, or an element or field of one'],
            'prefix -- on a call' => ['fn f() { return 1; } --f();', 'Can only use -- on a variable, or an element or field of one'],
            '+= on an expression' => ['($a + 1) += 2;', 'Can only use += on a variable, or an element or field of one'],
            'append with +=' => ['$a = []; $a[] += 1;', 'Cannot use += to append'],
            'append with ??=' => ['$a = []; $a[] ??= 1;', 'Cannot use ??= to append'],
            '??= on an expression' => ['1 ??= 2;', 'Can only use ??= on a variable, or an element or field of one'],
            'append with ++' => ['$a = []; $a[]++;', '[] can only be used to append in an assignment'],
            'double postfix' => ['$a = 1; $a++++;', "Expected ';' but found '++'"],
        ];
    }

    public function test_code_gen_for_compound_assignment_evaluates_keys_once()
    {
        $this->assertEquals(
            "NEW_ARRAY\nSTORE 0\nLOAD 0\nPOP\n"
            // $#key0 = 1 (checked once); $a[$#key0] = $a[$#key0] * 2 (a constant needs no hidden variable)
            ."PUSH 1\nKEY_CHECK\nSTORE 1\n"
            ."LOAD 1\nLOAD 0\nLOAD 1\nINDEX_GET_EXISTING\nPUSH 2\nMUL\nSET_PATH [k] 0\nPOP",
            $this->generateCode('$a = []; $a[1] *= 2;')
        );
        // A right side that isn't a constant is evaluated first, into a hidden variable
        $this->assertStringContainsString(
            "LOAD 1\nSTORE 2\nLOAD 2\nPOP\nLOAD 0\nLOAD 2\nADD\nSTORE 0",
            $this->generateCode('$x = 1; $y = 2; $x += $y;')
        );
    }

    public function test_code_gen_for_postfix_and_prefix_increment()
    {
        // echo $x++: read $x (the result), then $x = INC $x as a statement
        $this->assertEquals(
            "PUSH 1\nSTORE 0\nLOAD 0\nPOP\nLOAD 0\nLOAD 0\nINC\nSTORE 0\nLOAD 0\nPOP\nPRINT",
            $this->generateCode('$x = 1; echo $x++;')
        );
        // --$x leaves the new value
        $this->assertStringEndsWith("LOAD 0\nDEC\nSTORE 0\nLOAD 0\nPRINT", $this->generateCode('$x = 1; echo --$x;'));
        // $x++ as a statement is emitted as prefix: LOAD, INC, STORE once the VM drops STORE; LOAD; POP
        $this->assertSame("PUSH 1\nSTORE 0\nLOAD 0\nPOP\nLOAD 0\nINC\nSTORE 0\nLOAD 0\nPOP", $this->generateCode('$x = 1; $x++;'));
    }

    public function test_code_gen_for_coalesce()
    {
        $this->assertEquals(
            "LOAD_QUIET 0\nPUSH \"k\"\nINDEX_GET_QUIET\nJNN COALESCE_END_0\nPUSH 1\nLABEL COALESCE_END_0\nPRINT",
            $this->generateCode('echo $a["k"] ?? 1;')
        );
        $this->assertStringStartsWith("LOAD_QUIET_GLOBAL 0\nJNN", $this->generateCode('echo @g ?? 1;'));
    }

    public function test_code_gen_for_coalesce_assignment()
    {
        // $#key0 = "k"; $a[$#key0] ?? ($a[$#key0] = 1)
        $this->assertStringEndsWith(
            "PUSH \"k\"\nKEY_CHECK\nSTORE 1\nLOAD_QUIET 0\nLOAD 1\nINDEX_GET_QUIET\nJNN COALESCE_END_0\nLOAD 1\nPUSH 1\nSET_PATH [k] 0\nLABEL COALESCE_END_0\nPOP",
            $this->generateCode('$a = []; $a["k"] ??= 1;')
        );
    }

    public function test_coalesce_parse_errors()
    {
        $this->expectExceptionMessage("Unexpected ';' on line 1");
        $this->parse('echo $a ??;');
    }

    public function test_modulo()
    {
        // The sign follows the left operand; % binds like * and /
        $this->assertEquals("1\n-1\n1\n0\n8\n0\n", $this->executeCode(
            'echo 7 % 3; echo -7 % 3; echo 7 % -3; echo 6 % 3; echo 2 + 7 % 4 * 2; echo (-9223372036854775807 - 1) % -1;'
        ));
    }

    public function test_modulo_by_zero()
    {
        $this->expectExceptionMessage('Modulo by zero on line 1');
        $this->executeCode('echo 1 % 0;');
    }

    public function test_modulo_on_a_string()
    {
        $this->expectExceptionMessage('Cannot use % on string');
        $this->executeCode('echo "7" % 2;');
    }

    public function test_code_gen_for_modulo()
    {
        $this->assertEquals("PUSH 7\nPUSH 3\nMOD\nPRINT", $this->generateCode('echo 7 % 3;'));
    }

    public function test_relational_and_equality()
    {
        $this->assertEquals("true\nfalse\ntrue\ntrue\nfalse\ntrue\n", $this->executeCode(
            'echo 1 < 2; echo 2 < 2; echo 2 <= 2; echo 3 > 2; echo 2 >= 3; echo 1 != 2;'
        ));
    }

    public function test_equality_never_converts_between_strings_and_numbers()
    {
        $this->assertEquals("false\ntrue\nfalse\nfalse\ntrue\nfalse\nfalse\n", $this->executeCode(
            'echo "5" == 5; echo "5" != 5; echo "1" == "01"; echo true == "1"; echo 1 == 1.0; echo true == 1; echo null == 0;'
        ));
    }

    public function test_arrays_compare_element_by_element()
    {
        $this->assertEquals("true\ntrue\nfalse\nfalse\nfalse\ntrue\n", $this->executeCode(
            'echo [1, [2]] == [1, [2.0]]; echo [] == []; echo [1] == ["1"]; echo [1, 2] == [2, 1]; echo {0 => 1} == [1]; echo [1] != [1, 1];'
        ));
    }

    public function test_ordering_a_string_against_a_number_throws()
    {
        $this->expectExceptionMessage('Cannot use < on string and int on line 1');
        $this->executeCode('echo "5" < 6;');
    }

    public function test_ints_and_floats_compare_exactly()
    {
        // PHP converts the int to a float first, which would make 2^53 + 1 equal 2^53
        $this->assertEquals("false\ntrue\ntrue\ntrue\ntrue\nfalse\ntrue\ntrue\nfalse\n", $this->executeCode(
            'echo 9007199254740993 == 9007199254740992.0; echo 9007199254740993 > 9007199254740992.0;'
            .' echo 9007199254740992 == 9007199254740992.0; echo 1 < 1.5; echo -1 > -1.5;'
            .' echo 9223372036854775807 == 9223372036854775808.0; echo 9223372036854775807 < 9223372036854775808.0;'
            .' echo -9223372036854775807 - 1 == -9223372036854775808.0; echo in_array(9007199254740993, [9007199254740992.0]);'
        ));
    }

    public function test_ternary_evaluates_only_the_taken_branch()
    {
        $this->assertEquals("yes\nno\n1\n2\n", $this->executeCode(
            'echo true ? "yes" : error("not taken"); echo 0 ? error("not taken") : "no";'
            .' echo [] ? 1 : "" ? 2 : 1; $x = 1 ? 2 : 3; echo $x;'
        ));
    }

    public function test_ternary_is_right_associative_and_sits_between_coalesce_and_assignment()
    {
        $this->assertEquals("b\nc\n3\n7\n2\n2\n", $this->executeCode(
            '$n = 2; echo $n == 1 ? "a" : $n == 2 ? "b" : "c"; echo $n == 3 ? "a" : $n == 4 ? "b" : "c";'
            .' echo $missing ?? 3 ? 3 : 4; echo 1 ? 3 + 4 : 5; echo false ? 1 : ($y = 2); echo $y;'
        ));
    }

    public function test_ternary_middle_can_be_any_expression()
    {
        $this->assertEquals("5\n5\n", $this->executeCode('echo true ? $z = 5 : 0; echo $z;'));
    }

    public function test_ternary_parse_errors()
    {
        $this->expectExceptionMessage("Expected ':' but found ';' on line 1");
        $this->parse('echo 1 ? 2;');
    }

    public function test_code_gen_for_ternary()
    {
        $this->assertEquals(
            "PUSH true\nJZ TERNARY_ELSE_0\nPUSH 1\nJMP TERNARY_END_0\nLABEL TERNARY_ELSE_0\nPUSH 2\nLABEL TERNARY_END_0\nPRINT",
            $this->generateCode('echo true ? 1 : 2;')
        );
    }

    public function test_spaceship_gives_minus_one_zero_or_one_at_the_equality_level()
    {
        $this->assertEquals("-1\n0\n1\n-1\n1\n0\n-1\ntrue\n", $this->executeCode(
            'echo 1 <=> 2; echo 2 <=> 2; echo 3 <=> 2; echo "abc" <=> "abd"; echo "b" <=> "a"; echo 1 <=> 1.0; echo 1 <=> 1.5; echo 1 + 1 <=> 2 == 0;'
        ));
    }

    /**
     * @dataProvider spaceshipErrors
     */
    public function test_spaceship_follows_the_ordering_rules(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode($code);
    }

    public static function spaceshipErrors(): array
    {
        return [
            'string and number' => ['echo "1" <=> 1;', 'Cannot use <=> on string and int on line 1'],
            'bool' => ['echo true <=> false;', 'Cannot use <=> on bool on line 1'],
            'null' => ['echo null <=> 1;', 'Cannot use <=> on null on line 1'],
            'a list and a number' => ['echo [1] <=> 1;', 'Cannot use <=> on list on line 1'],
            'a map' => ['echo {} <=> {};', 'Cannot use <=> on map on line 1'],
            'lists whose elements cannot be ordered' => ['echo [1, 2] <=> [1, "2"];', 'Cannot use <=> on string and int on line 1'],
            'lists of maps' => ['echo [{}] < [{}];', 'Cannot use < on map on line 1'],
        ];
    }

    public function test_lists_order_element_by_element()
    {
        // The first pair that differs decides, and a shorter list the other starts with comes
        // first, so a sort by several keys is one comparison of two lists
        $this->assertEquals("[-1, 1, 0, -1, 0, -1]\n[true, false, true, true]\n[[1, \"z\"], [2, \"a\"], [2, \"b\"]]\n", $this->executeCode(<<<'CODE'
            echo [[1, 2] <=> [1, 3], ["b", 1] <=> ["a", 9], [1, 1.0] <=> [1, 1], [1] <=> [1, 0], [] <=> [], [[1, 2], 3] <=> [[1, 3], 0]];
            echo [[1, 2] < [2, "x"], [2] < [1, 9], [1, 2] >= [1, 2], [1, 2] <= [1, 2, 0]];
            echo sort([[2, "b"], [1, "z"], [2, "a"]], ($a, $b) -> $a <=> $b);
            CODE));
    }

    public function test_strict_equality_operator_is_gone()
    {
        $this->expectExceptionMessage("Unexpected '=' on line 1");
        $this->parse('echo 1 === 1;');
    }

    public function test_chained_equality_is_left_associative()
    {
        // (1 == 2) == 0 is true; the old parser read it as 1 == (2 == 0), which is false
        $this->assertEquals("true\n", $this->executeCode('echo 1 == 2 == false;'));
    }

    public function test_relational_binds_tighter_than_equality()
    {
        // (2 < 1) == 0, not 2 < (1 == 0)
        $this->assertEquals("true\n", $this->executeCode('echo 2 < 1 == false;'));
    }

    public function test_arithmetic_binds_tighter_than_relational()
    {
        $this->assertEquals("true\n", $this->executeCode('echo 1 + 2 < 2 * 2;'));
    }

    public function test_and_binds_tighter_than_or()
    {
        // 1 || (0 && 0) is true; (1 || 0) && 0 would be false
        $this->assertEquals("true\n", $this->executeCode('echo 1 || 0 && 0;'));
    }

    public function test_logical_operators_short_circuit()
    {
        $this->assertEquals("0\n0\n", $this->executeCode(
            '$x = 0; 0 && ($x = 1); echo $x; 1 || ($x = 2); echo $x;'
        ));
    }

    public function test_concatenation_converts_like_echo()
    {
        $this->assertEquals("ab\n12\nnulltrue\n[1]x\n1.5|\n", $this->executeCode(
            'echo "a" .. "b"; echo 1 .. 2; echo null .. true; echo [1] .. "x"; echo 1.5 .. "|";'
        ));
    }

    public function test_concatenation_binds_below_arithmetic_and_above_comparison()
    {
        $this->assertEquals("n = 3\ntrue\ntrue\n", $this->executeCode(
            'echo "n = " .. 1 + 2; echo "a" .. "b" == "ab"; echo 1 .. 2 == "12";'
        ));
    }

    public function test_concatenation_assignment()
    {
        $this->assertEquals("ab1\n[\"x1\"]\n", $this->executeCode(
            '$s = "a"; $s ..= "b" .. 1; echo $s; $a = ["x"]; $a[0] ..= 1; echo $a;'
        ));
    }

    public function test_plus_on_a_string_throws()
    {
        $this->expectExceptionMessage('Cannot use + on string on line 1');
        $this->executeCode('echo "1" + 1;');
    }

    public function test_plus_on_null_throws()
    {
        $this->expectExceptionMessage('Cannot use + on null on line 1');
        $this->executeCode('echo "x" + null;');
    }

    public function test_code_gen_for_concatenation()
    {
        $this->assertEquals("PUSH \"a\"\nPUSH 1\nCONCAT\nPRINT", $this->generateCode('echo "a" .. 1;'));
        // ..= on a plain variable appends in place instead of lowering to $s = $s .. "b",
        // which would load the string onto the stack and copy all of it on every append
        $this->assertEquals("PUSH \"b\"\nCONCAT_ASSIGN 0\nPOP", $this->generateCode('$s ..= "b";'));
        $this->assertEquals("PUSH \"b\"\nCONCAT_ASSIGN_GLOBAL 0\nPOP", $this->generateCode('@s ..= "b";'));
        // So do an element and a field, through a path ending in ..=, with no CONCAT to copy the string
        $this->assertEquals("PUSH 0\nKEY_CHECK\nSTORE 0\nLOAD 0\nPUSH \"b\"\nSET_PATH [k]..= 1\nPOP", $this->generateCode('$a[0] ..= "b";'));
        $this->assertStringNotContainsString("CONCAT\n", $this->generateCode('$o.s ..= "b";'));
    }

    public function test_arithmetic_on_strings_throws()
    {
        $this->expectExceptionMessage('Cannot use * on string');
        $this->executeCode('echo "a" * 2;');
    }

    public function test_unary_operators()
    {
        $this->assertEquals("true\nfalse\ntrue\n2\n-6\n", $this->executeCode(
            'echo !0; echo !5; echo !!5; echo -3 + 5; echo -(1 + 2) * 2;'
        ));
    }

    public function test_not_binds_tighter_than_equality()
    {
        // (!1) == 0
        $this->assertEquals("true\n", $this->executeCode('echo !1 == false;'));
    }

    public function test_assignment_is_right_associative_and_lowest()
    {
        $this->assertEquals("6\ntrue\n", $this->executeCode(
            '$a = $b = 3; echo $a + $b; $c = $a < 4 && $b > 2; echo $c;'
        ));
    }

    public function test_assignment_to_non_variable_throws()
    {
        $this->expectException(Exception::class);
        $this->parse('1 + $x = 3;');
    }

    public function test_comparison_in_if_condition()
    {
        $this->assertEquals("small\n", $this->executeCode(
            '$n = 3; if ($n > 10 || $n < 0) { echo "out"; } else if ($n <= 5) { echo "small"; } else { echo "big"; }'
        ));
    }

    public function test_code_gen_for_comparison_and_unary()
    {
        $this->assertEquals(
            "PUSH 1\nPUSH 2\nLT\nNOT\nPOP",
            $this->generateCode('!(1 < 2);')
        );
        $this->assertEquals("PUSH 3\nNEG\nPOP", $this->generateCode('-3;'));
    }

    public function test_code_gen_for_logical_and()
    {
        $this->assertEquals(
            "PUSH 1\nJZ AND_FALSE_0\nPUSH 0\nJZ AND_FALSE_0\nPUSH true\nJMP AND_END_0\n"
            ."LABEL AND_FALSE_0\nPUSH false\nLABEL AND_END_0\nPOP",
            $this->generateCode('1 && 0;')
        );
    }

    public function test_code_gen_for_logical_or()
    {
        $this->assertEquals(
            "PUSH 1\nNOT\nJZ OR_TRUE_0\nPUSH 0\nNOT\nJZ OR_TRUE_0\nPUSH false\nJMP OR_END_0\n"
            ."LABEL OR_TRUE_0\nPUSH true\nLABEL OR_END_0\nPOP",
            $this->generateCode('1 || 0;')
        );
    }
}
