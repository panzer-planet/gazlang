<?php

namespace GazLang\Tests;

class ClassTest extends GazLangTestCase
{
    private const ACCOUNT = <<<'CODE'
        class Account {
            #owner;
            #balance = 0;
            #history = [];
            fn _($owner, $balance = 10) { #owner = $owner; #balance = $balance; }
            fn deposit($amount) {
                #balance = #balance + $amount;
                $h = #history; $h[] = $amount; #history = $h;
                return #;
            }
            fn total() { return #balance; }
            fn log() { return #history; }
        }

        CODE;

    public function test_constructing_by_name_and_by_value()
    {
        $this->assertEquals(
            "Account {#owner => \"Werner\", #balance => 10, #history => []}\nAccount {#owner => \"Bob\", #balance => 5, #history => []}\n",
            $this->executeCode(self::ACCOUNT.<<<'CODE'
                echo Account("Werner");
                $make = Account;
                echo $make("Bob", 5);
                CODE)
        );
    }

    public function test_methods_fields_and_hash()
    {
        $this->assertEquals("17\ntrue\n3\n", $this->executeCode(<<<'CODE'
            class Counter {
                #n = 0;
                #step;
                fn _($step) { #step = $step; }
                fn tick() { #n = #n + #step; return #; }
                fn value() { return #n; }
                fn twice() { #tick(); return #tick(); }
            }
            $c = Counter(5);
            echo $c.twice().tick().value() + 2;
            echo $c.tick() == $c;
            echo Counter(1).twice().tick().value();
            CODE));
    }

    public function test_field_defaults_are_evaluated_for_each_object_in_order()
    {
        $this->assertEquals("[1] []\n{\"a\" => 2, \"b\" => 4}\n", $this->executeCode(<<<'CODE'
            class Bag {
                #items = [];
                #a = 2;
                #b = #a * 2;
                fn add($x) { #items = [#items, $x]; #items = [$x]; }
                fn list() { return #items; }
                fn both() { return {"a" => #a, "b" => #b}; }
            }
            $one = Bag();
            $two = Bag();
            $one.add(1);
            echo $one.list() .. " " .. $two.list();
            echo $one.both();
            CODE));
    }

    public function test_objects_are_handles_compared_by_identity()
    {
        $this->assertEquals("15\ntrue\nfalse\ntrue\n", $this->executeCode(self::ACCOUNT.<<<'CODE'
            fn pay($account) { $account.deposit(5); }
            $a = Account("W");
            $b = $a;
            pay($b);
            echo $a.total();
            echo $a == $b;
            echo Account("W") == Account("W");
            echo [$a] == [$b];
            CODE));
    }

    public function test_classes_and_objects_as_values()
    {
        $this->assertEquals(
            "[\"class\", \"object\"]\nclass Point\n[class Point]\ntrue\ntrue\nyes\nPoint {#x => 1}\nPoint {}\n",
            $this->executeCode(<<<'CODE'
                class Point {
                    #x;
                    #y;
                    fn set() { #x = 1; return #; }
                }
                echo [type_of(Point), type_of(Point())];
                echo Point;
                echo [Point];
                echo Point == Point;
                echo {"k" => Point}["k"] == Point;
                if (Point()) { echo "yes"; }
                echo Point().set();
                echo Point();
                CODE)
        );
    }

    public function test_an_object_holding_itself_prints_once()
    {
        $this->assertEquals("Node {#name => \"a\", #next => Node {...}}\nNode {#name => \"b\", #next => [Node {#name => \"a\", #next => Node {...}}]}\n", $this->executeCode(<<<'CODE'
            class Node {
                #name;
                #next;
                fn _($name) { #name = $name; #next = #; }
                fn point($to) { #next = $to; }
            }
            $a = Node("a");
            echo $a;
            $b = Node("b");
            $b.point([$a]);
            echo $b;
            CODE));
    }

    public function test_bound_methods()
    {
        $this->assertEquals("15\nfunction Account.deposit\ntrue\nfalse\nfalse\n20\n", $this->executeCode(self::ACCOUNT.<<<'CODE'
            $a = Account("W");
            $deposit = $a.deposit;
            $deposit(5);
            echo $a.total();
            echo $deposit;
            echo $a.deposit == $deposit;
            echo $a.total == $deposit;
            echo Account("W").deposit == $deposit;
            $handlers = {"save" => $a.deposit};
            $handlers["save"](5);
            echo $a.total();
            CODE));
    }

    public function test_hash_name_without_a_call_is_a_bound_method_and_closures_bind_the_receiver()
    {
        $this->assertEquals("[2, 4]\n3\n8\n", $this->executeCode(<<<'CODE'
            include "lib/functional.gaz";
            class Scaler {
                #factor;
                #count = 0;
                fn _($factor) { #factor = $factor; }
                fn scale($x) { #count = #count + 1; return $x * #factor; }
                fn all($xs) { return map($xs, #scale); }
                fn counter() { return () -> #count = #count + 1; }
            }
            $s = Scaler(2);
            echo $s.all([1, 2]);
            $inc = $s.counter();
            echo $inc();
            $s.all([1, 2, 3, 4]);
            echo $inc();
            CODE));
    }

    public function test_calling_a_field_that_holds_a_function()
    {
        $this->assertEquals("6\n", $this->executeCode(<<<'CODE'
            class Button {
                #on_click;
                fn _($handler) { #on_click = $handler; }
                fn click($x) { return #on_click($x); }
            }
            echo Button($x -> $x * 3).click(2);
            CODE));
    }

    public function test_keywords_as_member_names()
    {
        $this->assertEquals("class\n", $this->executeCode(<<<'CODE'
            class Tag {
                #class = "class";
                fn echo() { return #class; }
                fn if() { return #echo(); }
            }
            echo Tag().if();
            CODE));
    }

    /**
     * @dataProvider runtimeErrors
     */
    public function test_runtime_errors(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode(self::ACCOUNT.$code);
    }

    public static function runtimeErrors(): array
    {
        return [
            'unset field' => ['class P { #x; fn get() { return #x; } } echo P().get();', 'Property x of P is not set on line 14'],
            'unset field read with a dot' => ['class P { #x; } echo P().x;', 'Property x of P is not set on line 14'],
            'undeclared member' => ['echo Account("W").nope;', 'Account has no member nope on line 14'],
            'undeclared member called, before the arguments' => ['Account("W").nope(error("args"));', 'Account has no member nope on line 14'],
            'unset field called, before the arguments' => ['class P { #f; } P().f(error("args"));', 'Property f of P is not set on line 14'],
            'constructor as a member' => ['$a = Account("W"); $a._("X");', 'Cannot use the constructor of Account as a member on line 14'],
            'dot on a map' => ['echo {"a" => 1}.a;', 'Cannot use . on map on line 14'],
            'dot on null' => ['$x = null; echo $x.a;', 'Cannot use . on null on line 14'],
            'method arity' => ['Account("W").deposit(1, 2);', 'Method Account.deposit expects 1 arguments, 2 given on line 14'],
            'bound method arity' => ['$d = Account("W").deposit; $d();', 'Method Account.deposit expects 1 arguments, 0 given on line 14'],
            'class value arity' => ['$m = Account; $m();', 'Class Account expects 1 to 2 arguments, 0 given on line 14'],
            'calling a field that is not a function' => ['class P { #x = 1; } P().x();', 'Cannot call int on line 14'],
            'arithmetic' => ['echo Account("W") + 1;', 'Cannot use + on object on line 14'],
            'ordering' => ['echo Account < 1;', 'Cannot use < on class on line 14'],
            'key' => ['echo {Account("W") => 1};', 'Keys must be int or string, got object on line 14'],
            'index' => ['echo Account("W")[0];', 'Cannot use [] on object on line 14'],
            'foreach' => ['foreach (Account("W") as $x) {}', 'foreach expects a list or map, got object on line 14'],
            'error in a field default, located there' => ["class P {\n #x = 1 / 0;\n}\nP();", 'Division by zero on line 15'],
            'error in the constructor' => ["class P {\n fn _() { error(\"no\"); }\n}\n\$p = P();", 'no'],
        ];
    }

    public function test_errors_in_objects_are_caught()
    {
        $this->assertEquals("Property x of P is not set 1\n", $this->executeCode(<<<'CODE'
            class P { #x; fn get() { return #x; } }
            try { P().get(); } catch ($e) { echo $e["message"] .. " " .. $e["line"]; }
            CODE));
    }

    /**
     * @dataProvider syntaxErrors
     */
    public function test_syntax_errors(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->createParser($code)->parse();
    }

    public static function syntaxErrors(): array
    {
        return [
            'reserved word' => ["\$x = 1;\ninterface Shape {}", 'interface is reserved on line 2'],
            'reserved word in an expression' => ['echo private;', 'private is reserved on line 1'],
            'dot after #' => ['class P { fn f() { return #.name; } }', 'Write #name, not #.name on line 1'],
            'bare ##' => ['echo ##;', "## alone is not allowed: write ##name for the parent's version of a method on line 1"],
            'class inside a block' => ['if (true) { class P {} }', 'Classes can only be declared at the top level on line 1'],
            '# outside a method' => ["\necho #;", 'Cannot use # outside a method on line 2'],
            '#name outside a method' => ['fn f() { return #x; }', 'Cannot use #x outside a method on line 1'],
            '#name in a lambda outside a class' => ['$f = () -> #x;', 'Cannot use #x outside a method on line 1'],
            'undeclared member' => ["class P {\n fn f() { return #nope; }\n}", 'P has no member #nope on line 2'],
            'assigning to a method' => ['class P { fn f() { #f = 1; } }', 'Cannot assign to method #f on line 1'],
            'the constructor as a member' => ['class P { fn _() {} fn f() { #_(); } }', "Cannot use the constructor _ as a member: construct with P(...), or call ##_(...) in a child's constructor on line 1"],
            'duplicate field' => ["class P {\n #x;\n #x = 1;\n}", 'P already has a member x on line 3'],
            'field and method with one name' => ['class P { #x; fn x() {} }', 'P already has a member x on line 1'],
            'constructor returning a value' => ['class P { fn _() { return 1; } }', "A constructor can't return a value: constructing gives the object on line 1"],
            'a lambda in a constructor may return' => ['class P { fn _() { $f = () -> { return 1; }; return; } } echo P() .. ;', "Unexpected ';' on line 1"],
            'local variable in a field default' => ["\$y = 1;\nclass P { #x = \$y; }", "The default of #x can't use \$y: fields have no local variables on line 2"],
            'class named like a builtin' => ['class len {}', 'len is a builtin function on line 1'],
            'class named like a function' => ['fn P() {} class P {}', 'Function P is already declared on line 1'],
            'function named like a class' => ['class P {} fn P() {}', 'Class P is already declared on line 1'],
            'constructing with the wrong argument count' => ["class P { fn _(\$a) {} }\n\nP();", 'Class P expects 1 arguments, 0 given on line 3'],
            'constructing without a constructor' => ['class P {} P(1);', 'Class P expects 0 arguments, 1 given on line 1'],
            'method call arity' => ['class P { fn f($a) { return #f(); } }', 'Method P.f expects 1 arguments, 0 given on line 1'],
            'something else in a class body' => ['class P { echo 1; }', "Expected a field (#name) or a method (fn) but found 'echo' on line 1"],
            'duplicate method parameter' => ['class P { fn f($a, $a) {} }', 'Duplicate parameter $a in method P.f on line 1'],
        ];
    }
}
