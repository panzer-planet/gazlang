<?php

namespace GazLang\Tests;

class SpreadTest extends GazLangTestCase
{
    public function test_a_list_spreads_its_elements_in_place()
    {
        $this->assertEquals("[1, 2, 3, 4]\n[0, 1, 1]\n[]\n[[1], 2]\n", $this->executeCode(<<<'CODE'
            $a = [2, 3];
            echo [1, ...$a, ...[], 4];
            $l = [1];
            echo [0, ...$l, ...$l,];
            echo [...[]];
            echo [...[[1]], 2];
            CODE));
    }

    public function test_joining_and_prepending()
    {
        $this->assertEquals("[\"a\", \"b\", \"c\"]\n[0, 1, 2]\n", $this->executeCode(<<<'CODE'
            $first = ["a"];
            $rest = ["b", "c"];
            echo [...$first, ...$rest];
            $l = [1, 2];
            $l = [0, ...$l];
            echo $l;
            CODE));
    }

    /**
     * Lists are values, so the new list shares nothing with the ones spread into it
     */
    public function test_the_result_is_a_new_list()
    {
        $this->assertEquals("[1]\n[1, 2]\n", $this->executeCode('$a = [1]; $b = [...$a]; $b[] = 2; echo $a; echo $b;'));
    }

    /**
     * Elements are worked out left to right, and a bad spread fails before the elements after it
     */
    public function test_order_of_evaluation()
    {
        $this->assertEquals("abc[1, 2, 3]\nab\n", $this->executeCode(<<<'CODE'
            fn say($s, $v) { print($s); return $v; }
            echo [say("a", 1), ...say("b", [2]), say("c", 3)];
            try { [say("a", 1), ...say("b", 5), say("c", 3)]; } catch ($e) {}
            echo "";
            CODE));
    }

    public function test_a_map_spreads_its_entries_later_ones_winning()
    {
        // A key already there keeps its place and takes the later value, as a duplicate key in
        // a literal does, so defaults then options is {...$defaults, ...$options}
        $this->assertEquals("{\"colour\" => \"red\", \"size\" => 5}\n{\"size\" => 2, \"colour\" => \"red\", \"extra\" => true}\n{}\nred blue\n", $this->executeCode(<<<'CODE'
            $defaults = {"colour" => "red", "size" => 2};
            echo {...$defaults, ...{"size" => 5}};
            echo {"size" => 1, ...$defaults, "extra" => true};
            echo {...{}};
            $copy = {...$defaults};
            $copy["colour"] = "blue";
            echo $defaults["colour"] .. " " .. $copy["colour"];
            CODE));
    }

    public function test_map_spread_evaluates_left_to_right()
    {
        $this->assertEquals("abc{\"a\" => 1, \"c\" => 3}\n", $this->executeCode(<<<'CODE'
            fn say($s, $v) { print($s); return $v; }
            echo {...say("a", {"a" => 1}), say("b", "c") => say("c", 3)};
            CODE));
    }

    public function test_constants_can_spread_constants()
    {
        $this->assertEquals("[\"a\", \"b\", \"x\"]\n", $this->executeCode(<<<'CODE'
            const LETTERS = ["a", "b"];
            kind T { const ALL = [...LETTERS, "x"]; }
            echo T::ALL;
            CODE));
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
            'a map' => ['echo [...{"a" => 1}];', 'Cannot spread map: only a list can be on line 1'],
            'a string' => ['echo [..."ab"];', 'Cannot spread string: only a list can be on line 1'],
            'null' => ['$n = null; echo [...$n];', 'Cannot spread null: only a list can be on line 1'],
            'located at the ...' => ["echo [\n1,\n...\n5];", 'Cannot spread int: only a list can be on line 3'],
            'a list into a map' => ['echo {...[1, 2]};', 'Cannot spread list: only a map can be on line 1'],
            'null into a map' => ['$n = null; echo {"a" => 1, ...$n};', 'Cannot spread null: only a map can be on line 1'],
            'a map located at the ...' => ["echo {\n\"a\" => 1,\n...\n5};", 'Cannot spread int: only a map can be on line 3'],
        ];
    }

    /**
     * @dataProvider syntaxErrors
     */
    public function test_syntax_errors(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->parse($code);
    }

    public static function syntaxErrors(): array
    {
        return [
            'in a call' => ['echo len(...$a);', '... only spreads into a list or map literal, as in [...$a, 1] or {...$m, "k" => 1} on line 1'],
            'alone' => ['$x = ...$a;', '... only spreads into a list or map literal, as in [...$a, 1] or {...$m, "k" => 1} on line 1'],
            'a rest pattern' => ['[$a, ...$b] = [1, 2];', "A pattern can't take the rest with ...: take the list apart with slice() on line 1"],
            'a rest pattern in foreach' => ['foreach ([] as [...$b]) {}', "A pattern can't take the rest with ...: take the list apart with slice() on line 1"],
            'a constant spreading a non-list' => ['const C = [..."s"];', 'Cannot spread string: only a list can be on line 1'],
            'a constant spreading a variable' => ['const C = [...$a];', "A constant's value can only use literals, operators and other constants on line 1"],
            'a constant map spreading a list' => ['const C = {...[1]};', 'Cannot spread list: only a map can be on line 1'],
        ];
    }

    public function test_code_gen()
    {
        $this->assertEquals(
            "NEW_ARRAY\nPUSH 1\nARRAY_PUSH\nLOAD 0\nARRAY_EXTEND\nPRINT",
            $this->generateCode('echo [1, ...$a];')
        );
    }
}
