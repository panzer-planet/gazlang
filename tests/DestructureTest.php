<?php

namespace GazLang\Tests;

class DestructureTest extends GazLangTestCase
{
    public function test_taking_a_list_apart_into_variables()
    {
        $this->assertEquals("1 2\n2 1\nx null\n", $this->executeCode(<<<'CODE'
            [$a, $b] = [1, 2];
            echo "{$a} {$b}";
            [$a, $b] = [$b, $a];
            echo "{$a} {$b}";
            [$c, $d,] = ["x", null];
            echo "{$c} {$d}";
            CODE));
    }

    public function test_any_assignable_target()
    {
        $this->assertEquals("[\"one\", 2] global {\"k\" => 3} P {#x => 4, #y => 5} 6\n", $this->executeCode(<<<'CODE'
            kind P {
                #x;
                #y;
                #inner;
                fn set($pair) { [#x, #y] = $pair; return #; }
            }
            $list = [0, 2];
            $map = {};
            $p = P().set([4, 5]);
            $q = P();
            [$list[0], @g, $map["k"], $q.inner] = ["one", "global", 3, 6];
            echo "{$list} {@g} {$map} {$p} {$q.inner}";
            CODE));
    }

    public function test_the_value_is_the_list_and_the_list_is_worked_out_first()
    {
        // The list first, then each target's keys as it is written, left to right
        $this->assertEquals("[1, 2]\n[\"list\", \"a\", \"b\"]\n", $this->executeCode(<<<'CODE'
            echo [$a, $b] = [1, 2];
            fn key($name) { @order[] = $name; return 0; }
            @order = [];
            $x = [0];
            $y = [0];
            [$x[key("a")], $y[key("b")]] = [key("list") + 1, 2];
            echo @order;
            CODE));
    }

    public function test_foreach_takes_each_value_apart()
    {
        $this->assertEquals("0: Ann is 31\n1: Bob is 42\nfirst 1 2\n", $this->executeCode(<<<'CODE'
            foreach ([["Ann", 31], ["Bob", 42]] as $i => [$name, $age]) {
                echo "{$i}: {$name} is {$age}";
            }
            foreach ({"first" => [1, 2]} as $key => [$a, $b]) {
                echo "{$key} {$a} {$b}";
            }
            CODE));
    }

    public function test_assigned_targets_are_local_to_a_lambda()
    {
        $this->assertEquals("15\n1\n", $this->executeCode(<<<'CODE'
            $x = 1;
            $sum = $pair -> { [$x, $y] = $pair; return $x + $y; };
            echo $sum([7, 8]);
            echo $x;
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
            'too many elements' => ["\n[\$a] = [1, 2];", 'Cannot destructure a list of 2 elements into 1 on line 2'],
            'too few elements' => ['[$a, $b, $c] = [1, 2];', 'Cannot destructure a list of 2 elements into 3 on line 1'],
            'a map' => ['[$a, $b] = {"a" => 1, "b" => 2};', 'Cannot destructure map: only a list can be on line 1'],
            'a string' => ['[$a, $b] = "ab";', 'Cannot destructure string: only a list can be on line 1'],
            'in foreach' => ['foreach ([[1, 2], [3]] as [$a, $b]) { echo $a; }', 'Cannot destructure a list of 1 element into 2 on line 1'],
            'a bad target fails where it is written' => ['$l = []; [$a, $l[5]] = [1, 2];', 'Index out of range: 5 on line 1'],
        ];
    }

    public function test_a_failed_shape_writes_nothing()
    {
        $this->assertEquals("1\n", $this->executeCode('$a = 1; try { [$a, $b] = [9]; } catch ($e) {} echo $a;'));
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
            'compound assignment' => ['[$a, $b] += [1, 2];', 'Cannot use += to take a list apart: only = can on line 1'],
            'empty pattern' => ['[] = [];', 'Nothing to take apart: write at least one target in [...] on line 1'],
            'a nested pattern' => ['[[$a, $b], $c] = [[1, 2], 3];', 'Can only use = on a variable, or an element or field of one on line 1'],
            'a literal target' => ['[$a, 1] = [1, 2];', 'Can only use = on a variable, or an element or field of one on line 1'],
            'a map pattern' => ['{"a" => $a} = {"a" => 1};', 'Can only use = on a variable, or an element or field of one on line 1'],
            'an append target' => ['[$a[]] = [1];', '[] can only be used to append in an assignment on line 1'],
            'a foreach pattern of non-variables' => ['foreach ([] as [$a[0], $b]) {}', 'A foreach pattern takes variables only on line 1'],
            'a foreach key pattern' => ['foreach ({} as [$k] => $v) {}', 'A foreach key is a variable, not a pattern on line 1'],
            'an undeclared field target' => ['kind P { fn f() { [#nope] = [1]; } }', 'P has no member #nope on line 1'],
            'a method target' => ['kind P { fn f() { [#f] = [1]; } }', 'Cannot assign to method #f on line 1'],
        ];
    }

    public function test_code_gen()
    {
        $this->assertEquals(
            "PUSH [1, 2]\nDESTRUCTURE 2\nSTORE 0\nLOAD 0\nPUSH 0\nINDEX_GET\nSTORE 1\nLOAD 1\nPOP\nLOAD 0\nPUSH 1\nINDEX_GET\nSTORE 2\nLOAD 2\nPOP\nLOAD 0\nPOP",
            $this->generateCode('[$a, $b] = [1, 2];')
        );
    }
}
