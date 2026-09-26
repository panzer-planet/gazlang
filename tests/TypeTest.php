<?php

namespace GazLang\Tests;

/**
 * Declared types, checked when the program runs: strict, never converting, except that an int
 * is accepted where a float is asked and arrives as a float
 */
class TypeTest extends GazLangTestCase
{
    public function test_a_parameter_of_the_wrong_type_is_an_error_naming_the_function()
    {
        $this->expectExceptionMessage('total() expects $n to be int, got string on line 1');
        $this->executeCode('fn total(int $n) { return $n; } echo total("5");');
    }

    public function test_a_method_names_its_kind_and_a_union_is_written_with_bars()
    {
        $this->expectExceptionMessage('Account.deposit() expects $amount to be int|float, got null on line 1');
        $this->executeCode('kind Account { pub fn deposit(int|float $amount) { return $amount; } } Account().deposit(null);');
    }

    public function test_a_return_of_the_wrong_type_is_an_error()
    {
        $this->expectExceptionMessage('total() should return int, got string on line 1');
        $this->executeCode('fn total(): int { return "x"; } total();');
    }

    public function test_falling_off_the_end_returns_null_which_the_return_type_sees()
    {
        $this->expectExceptionMessage('total() should return int, got null on line 1');
        $this->executeCode('fn total(): int { } total();');
    }

    public function test_a_field_keeps_its_type_from_outside_the_kind()
    {
        $this->expectExceptionMessage('Account #balance must be int, got string on line 1');
        $this->executeCode('kind Account { pub int #balance = 0; } $a = Account(); $a.balance = "x";');
    }

    public function test_a_field_keeps_its_type_from_inside_the_kind()
    {
        $this->expectExceptionMessage('Account #balance must be int, got string on line 1');
        $this->executeCode('kind Account { pub int #balance = 0; pub fn set($v) { #balance = $v; } } Account().set("x");');
    }

    public function test_a_static_field_keeps_its_type()
    {
        $this->expectExceptionMessage('Counter::count must be int, got string on line 1');
        $this->executeCode('kind Counter { pub static int #count = 0; } Counter::count = "x";');
    }

    public function test_a_constructor_is_named_as_the_call_that_makes_the_object()
    {
        $this->expectExceptionMessage('Account() expects $owner to be string, got int on line 1');
        $this->executeCode('kind Account { fn _(pub string #owner) {} } Account(1);');
    }

    public function test_a_lambda_is_named_where_it_was_made()
    {
        $this->expectExceptionMessage('-> on line 1 expects $x to be int, got string on line 1');
        $this->executeCode('$f = (int $x) -> $x; $f("x");');
    }

    public function test_an_int_arrives_as_a_float_where_a_float_is_asked()
    {
        $this->assertSame("4.0 float\n2.0 float\n3.0\n", $this->executeCode(<<<'CODE'
            fn area(float $r): float { return $r * $r; }
            fn half(int $n): float { return $n; }
            kind Box { pub float #width = 1; }
            echo area(2) .. " " .. type_of(area(2));
            echo half(2) .. " " .. type_of(half(2));
            $b = Box();
            $b.width = 3;
            echo $b.width;
            CODE));
    }

    public function test_nothing_else_converts()
    {
        $this->expectExceptionMessage('whole() expects $n to be int, got float on line 1');
        $this->executeCode('fn whole(int $n) { return $n; } whole(2.0);');
    }

    public function test_a_kind_admits_its_children_and_objects_are_named_by_their_kind()
    {
        $this->assertSame("circle\nf() expects \$s to be Shape, got Square\n", $this->executeCode(<<<'CODE'
            kind Shape { fn _(pub string #name) {} }
            kind Circle extends Shape {}
            kind Square {}
            fn f(Shape $s): Shape { return $s; }
            echo f(Circle("circle")).name;
            try { f(Square()); } catch (Error $e) { echo $e.message; }
            CODE));
    }

    public function test_a_type_error_is_caught_and_its_trace_shows_the_caller()
    {
        $this->assertSame("caught\ntotal on line 1\ntop level on line 2\n", $this->executeCode(<<<'CODE'
            fn total(int $n) { return $n; }
            try { total("x"); } catch (Error $e) { echo "caught"; echo join($e.trace, "\n"); }
            CODE));
    }

    public function test_a_default_is_held_to_the_type_too()
    {
        $this->expectExceptionMessage('f() expects $label to be string|null, got int on line 1');
        $this->executeCode('fn f(?string $label = 5) { return $label; } f();');
    }

    public function test_the_return_type_null_is_for_a_function_that_returns_nothing()
    {
        $this->assertSame("null\n", $this->executeCode('fn f(): null { echo "x" == "y" ? 1 : null; } fn g(): null { return; } echo g();'));
    }

    public function test_generics_are_not_yet_a_type()
    {
        $this->expectExceptionMessage('Generic types aren\'t supported yet: write list, not list<...> on line 1');
        $this->executeCode('fn f(list<int> $xs) { return $xs; }');
    }

    public function test_a_kind_cannot_take_a_type_name()
    {
        $this->expectExceptionMessage("int is a type name, so a kind can't be called that on line 1");
        $this->executeCode('kind int {}');
    }

    public function test_an_override_keeps_the_parent_types()
    {
        $this->expectExceptionMessage("Method B.f must take \$x as int, as A.f does: an override keeps the parent's types on line 1");
        $this->executeCode('kind A { pub fn f(int $x) {} } kind B extends A { pub fn f(string $x) {} }');
    }

    public function test_a_template_header_takes_types()
    {
        $this->assertSame("<p>Ann</p>\n", $this->executeCode(<<<'CODE'
            include "tests/gaz/templates/views/typed.gazml";
            print(typed({"name" => "Ann"}, 2));
            CODE));
    }

    public function test_a_template_header_type_is_checked()
    {
        $this->expectExceptionMessage('typed() expects $times to be int, got string at tests/gaz/templates/views/typed.gazml:1');
        $this->executeCode(<<<'CODE'
            include "tests/gaz/templates/views/typed.gazml";
            echo typed({"name" => "Ann"}, "2");
            CODE);
    }

    public function test_a_builtin_kind_named_only_in_a_type_is_compiled_in()
    {
        // Error, Shared and Html are compiled into a program only when it uses them, and a
        // type is a use: before, the program failed to load
        $this->assertSame("1\nError\nmade\n", $this->executeCode(<<<'CODE'
            fn f(Html|int $x) {
                return $x;
            }
            fn g(Error $e) {
                return kind_name($e);
            }
            $h = (Shared $s) -> 1;
            echo f(1);
            try {
                error("x");
            } catch ($e) {
                echo g($e);
            }
            echo "made";
            CODE));
    }

    public function test_a_function_cannot_catch_its_own_return_type_error()
    {
        // The return is checked once the function's own try blocks are left, their finally
        // blocks run: the caller hears of it
        $this->assertSame("finally\ncaller: f() should return int, got string\n", $this->executeCode(<<<'CODE'
            fn f(): int {
                try {
                    return "x";
                } catch ($e) {
                    echo "f caught it";
                } finally {
                    echo "finally";
                }
                return 0;
            }
            try {
                f();
            } catch (Error $e) {
                echo "caller: " .. $e.message;
            }
            CODE));
    }

    public function test_an_override_may_write_the_same_union_in_another_order()
    {
        $this->assertSame("1\n", $this->executeCode(<<<'CODE'
            kind P {
                pub fn m(int|string $x): ?int {
                    return null;
                }
            }
            kind C extends P {
                pub fn m(string|int $x): null|int {
                    return 1;
                }
            }
            echo C().m("a");
            CODE));
    }
}
