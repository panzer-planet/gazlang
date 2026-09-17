<?php

namespace GazLang\Tests;

use GazLang\Lexer\Token;

class ArrayTest extends GazLangTestCase
{
    public function test_lexes_array_syntax()
    {
        $lexer = $this->createLexer('[ ] => = ==');
        foreach ([Token::LEFT_BRACKET, Token::RIGHT_BRACKET, Token::DOUBLE_ARROW, Token::ASSIGN, Token::EQUALS] as $type) {
            $this->assertEquals($type, $lexer->get_next_token()->type);
        }
    }

    public function test_literals_print_as_literals()
    {
        $this->assertEquals("[]\n[1, \"two\", true, null]\n[\"a\" => 1, 5 => [2, 3]]\n[\"q\\\"uote\"]\n", $this->executeCode(
            'echo []; echo [1, "two", true, null]; echo ["a" => 1, 5 => [2, 3],]; echo ["q\"uote"];'
        ));
    }

    public function test_duplicate_keys_keep_the_last_value()
    {
        $this->assertEquals("[\"a\" => 2]\n", $this->executeCode('echo ["a" => 1, "a" => 2];'));
    }

    public function test_indexing_arrays_and_strings()
    {
        $this->assertEquals("2\ny\nb\nnull\nnull\nnull\n", $this->executeCode(
            '$a = [1, 2, ["x" => "y"]]; echo $a[1]; echo $a[2]["x"]; echo "abc"[1];'
            .' echo $a[9]; echo $a[2]["nope"]; echo "abc"[3];'
        ));
    }

    public function test_assignment_through_indexes_and_append()
    {
        $this->assertEquals("[\"list\" => [1, 9, 3], \"n\" => 5]\n3\n", $this->executeCode(
            '$m = ["list" => [1, 2]]; $m["list"][] = 3; $m["list"][1] = 9; $m["n"] = 5; echo $m; echo $m["list"][] = 3;'
        ));
    }

    public function test_assignment_copies_arrays()
    {
        $this->assertEquals("[1]\n[1, 2]\n[[1, 2]]\n[[1, 2, 3]]\n", $this->executeCode(
            '$a = [1]; $b = $a; $b[] = 2; echo $a; echo $b;'
            // In-place writes use PHP references; a later copy must not share the nested array
            .' $c = [[1]]; $c[0][] = 2; $d = $c; $d[0][] = 3; echo $c; echo $d;'
        ));
    }

    public function test_functions_get_a_copy()
    {
        $this->assertEquals("[1, 2]\n[1, 2, 3]\n", $this->executeCode(
            'function add($list) { $list[] = 3; return $list; } $a = [1, 2]; $b = add($a); echo $a; echo $b;'
        ));
    }

    public function test_global_arrays_are_shared()
    {
        $this->assertEquals("[\"a\", \"b\"]\n", $this->executeCode(
            '@stack = []; function push($v) { @stack[] = $v; } push("a"); push("b"); echo @stack;'
        ));
    }

    public function test_keys_are_evaluated_before_the_value()
    {
        $this->assertEquals("[1 => 2]\n", $this->executeCode('$i = 1; $a = []; $a[$i] = $i = 2; echo $a;'));
    }

    public function test_keys_are_evaluated_left_to_right()
    {
        $this->assertEquals("key 0\nkey 1\n[[0, 5], [0, 0]]\n", $this->executeCode(
            'function k($n) { echo "key " .. $n; return $n; } $a = [[0, 0], [0, 0]]; $a[k(0)][k(1)] = 5; echo $a;'
        ));
    }

    public function test_array_is_read_after_the_keys_and_value_run()
    {
        $this->assertEquals("[1, 1]\n[5, 6, 0]\n", $this->executeCode(
            '$e = []; $e[] = $e[] = 1; echo $e;'
            .' @g = [1]; function reset_g() { @g = [5, 6, 7]; return 0; } @g[2] = reset_g(); echo @g;'
        ));
    }

    /**
     * @dataProvider badKeysBeforeValues
     */
    public function test_a_bad_key_fails_before_later_keys_and_the_value_run(string $code)
    {
        // executeCode also runs this on the VM, which must check keys just as early
        $this->assertEquals("Array keys must be int or string\n", $this->executeCode(
            'function side() { echo "side effect"; return 1; } $a = []; '
            .'try { '.$code.' } catch ($e) { echo slice($e["message"], 0, 32); }'
        ));
    }

    public static function badKeysBeforeValues(): array
    {
        return [
            'assignment' => ['$a[true] = side();'],
            'assignment, first of two keys' => ['$a[null][side()] = 1;'],
            'array literal' => ['$x = [true => side()];'],
            'compound assignment' => ['$a[1.5] += side();'],
            'increment, first of two keys' => ['$a[true][side()]++;'],
        ];
    }

    public function test_len()
    {
        $this->assertEquals("0\n3\n5\n", $this->executeCode('echo len([]); echo len([1, [2, 3], 4]); echo len("hello");'));
    }

    public function test_len_rejects_other_types()
    {
        $this->expectExceptionMessage('len() expects array or string, got int');
        $this->executeCode('echo len(5);');
    }

    public function test_equality_truthiness_and_concatenation()
    {
        $this->assertEquals("true\nfalse\nfalse\nfalse\nempty\nx[1]\n", $this->executeCode(
            'echo [1, [2]] == [1, [2]]; echo [1] == [true]; echo ["a" => 1, "b" => 2] == ["b" => 2, "a" => 1]; echo [] == null;'
            .' if ([]) { echo "full"; } else { echo "empty"; } echo "x" .. [1];'
        ));
    }

    /**
     * @dataProvider runtimeErrors
     */
    public function test_runtime_errors(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode($code);
    }

    public static function runtimeErrors(): array
    {
        return [
            'arithmetic' => ['echo [1] + 1;', 'Cannot use + on array'],
            'ordering' => ['echo [1] < [2];', 'Cannot use < on array'],
            'negation' => ['echo -[1];', 'Cannot use - on array'],
            'indexing an int' => ['$x = 5; echo $x[0];', 'Cannot use [] on int'],
            'writing into an int' => ['$x = 5; $x[0] = 1;', 'Cannot use [] on int'],
            'bool key' => ['echo [true => 1];', 'Array keys must be int or string, got bool'],
            'null index' => ['$a = []; echo $a[null];', 'Array keys must be int or string, got null'],
            'string position' => ['echo "abc"["1"];', 'String positions must be int, got string'],
            'undefined variable' => ['$a[0] = 1;', 'Undefined variable: $a'],
            'missing intermediate key' => ['$a = []; $a["x"]["y"] = 1;', 'Undefined key: x'],
        ];
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
            'reading []' => ['$a = []; echo $a[];', '[] can only be used to append in an assignment'],
            'assigning into a call' => ['function f() { return []; } f()[0] = 1;', 'Can only use = on a variable or an element of one'],
            'len arity' => ['echo len([], []);', 'Function len expects 1 arguments, 2 given'],
            'redeclaring len' => ['function len($x) { }', 'len is a builtin function'],
            'unterminated literal' => ['echo [1, 2;', "Expected ',' but found ';'"],
        ];
    }

    public function test_code_gen_for_literals_and_indexing()
    {
        $this->assertEquals(
            "PUSH 1\nSTORE 0\nLOAD 0\nPOP\n"
            ."NEW_ARRAY\nLOAD 0\nARRAY_PUSH\nPUSH_STR \"k\"\nKEY_CHECK\nPUSH true\nARRAY_SET\nPUSH 0\nINDEX_GET\nPRINT",
            $this->generateCode('$x = 1; echo [$x, "k" => true][0];')
        );
    }

    public function test_code_gen_builds_constant_literals_once()
    {
        $this->assertEquals(
            "PUSH [\" \", [\"k\" => null, 1 => 2.5]]\nPRINT",
            $this->generateCode('echo [" ", ["k" => null, "1" => 2.5]];')
        );
        // Anything that isn't a constant with a valid key is built at runtime, so it fails (or
        // runs) exactly when the interpreter's does
        $this->assertStringStartsWith('NEW_ARRAY', $this->generateCode('echo [true => 1];'));
        $this->assertStringStartsWith('NEW_ARRAY', $this->generateCode('echo [[1, -1]];'));
    }

    public function test_constant_literals_are_not_shared_between_evaluations()
    {
        $this->assertEquals("[[1, 2], [1]]\n", $this->executeCode(
            'function fresh() { return [1]; } $a = fresh(); $a[] = 2; echo [$a, fresh()];'
        ));
    }

    public function test_code_gen_for_index_assignment_and_append()
    {
        $this->assertEquals(
            "NEW_ARRAY\nSTORE 0\nLOAD 0\nPOP\n"
            ."PUSH_STR \"k\"\nKEY_CHECK\nPUSH 0\nKEY_CHECK\nPUSH 5\nSET_PATH 2 0\nPOP\n"
            ."LOAD 0\nAPPEND_PATH_GLOBAL 0 0\nPOP\n"
            ."LOAD 0\nCALL_BUILTIN len 1\nPRINT",
            $this->generateCode('$a = []; $a["k"][0] = 5; @all[] = $a; echo len($a);')
        );
    }
}
