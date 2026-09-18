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

    public function test_writing_fields_through_hash()
    {
        $this->assertEquals("[1, 2] {\"a\" => 2} 2 5 4\n7 [] 7 {\"k\" => [1]}\n", $this->executeCode(<<<'CODE'
            class Stats {
                #items = [];
                #counts = {"a" => 0};
                #n = 0;
                #lazy;
                #cache = {};
                fn run() {
                    #items[] = 1;
                    #items[] = 2;
                    #counts["a"] += 2;
                    #n++;
                    ++#n;
                    $old = #n++;
                    $five = #n += 2;
                    #n--;
                    return "{#items} {#counts} {$old} {$five} {#n}";
                }
                fn lazily() {
                    $first = #lazy ??= 7;
                    $second = #lazy ??= error("not run");
                    #cache["k"] ??= [];
                    $empty = #cache["k"];
                    #cache["k"][] = 1;
                    return "{$first} {$empty} {$second} {#cache}";
                }
            }
            $s = Stats();
            echo $s.run();
            echo $s.lazily();
            CODE));
    }

    public function test_writing_fields_through_a_dot()
    {
        $this->assertEquals("P {#x => 3, #tags => [\"a\", \"b\"], #next => P {#x => 9, #tags => []}}\n[P {#x => 5, #tags => []}]\n2 3 8 [6]\n1\n4\n", $this->executeCode(<<<'CODE'
            class P {
                #x;
                #tags = [];
                #next;
            }
            $p = P();
            $p.x = 1;
            $p.x += 2;
            $p.tags[] = "a";
            $p.tags[] = "b";
            $p.next = P();
            $p.next.x = 9;
            echo $p;
            $rows = [P()];
            $copy = $rows;
            $copy[0].x = 5;
            echo $rows;
            $q = P();
            $q.x = 2;
            $old = $q.x++;
            $q.tags = [2];
            $q.tags[0] *= 3;
            echo "{$old} {$q.x} {$q.x += 5} {$q.tags}";
            @g = P();
            @g.x = 1;
            echo @g.x;
            $set = $v -> $p.x = $v;
            $set(4);
            echo $p.x;
            CODE));
    }

    public function test_lists_in_fields_stay_values()
    {
        $this->assertEquals("[1] [1, 2]\n", $this->executeCode(<<<'CODE'
            class Bag { #items = []; }
            $b = Bag();
            $b.items[] = 1;
            $copy = $b.items;
            $b.items[] = 2;
            echo "{$copy} {$b.items}";
            CODE));
    }

    public function test_coalesce_reads_unset_fields_as_null()
    {
        $this->assertEquals("default null-target missing-key 1\n", $this->executeCode(<<<'CODE'
            class P { #x; #y = 1; }
            $p = P();
            $none = null;
            $m = {};
            echo ($p.x ?? "default") .. " " .. ($none.x ?? "null-target") .. " " .. ($m["k"].x ?? "missing-key") .. " " .. ($p.y ?? 2);
            CODE));
    }

    public function test_appending_to_a_field_is_linear()
    {
        $this->assertEquals("20000\n", $this->executeCode(<<<'CODE'
            class Bag {
                #items = [];
                fn fill($n) { for ($i = 0; $i < $n; $i++) { #items[] = $i; } return len(#items); }
            }
            echo Bag().fill(20000);
            CODE));
    }

    public function test_code_for_paths_through_fields()
    {
        $this->assertStringEndsWith(
            "POP\nPUSH 0\nKEY_CHECK\nPUSH 5\nSET_PATH [k].total 0\nPOP\nclass P\nLOAD_THIS\nRET\nfn P.f 0 0\nPUSH 1\nSET_PATH_THIS .items[]\nPOP\nPUSH null\nRET",
            $this->generateCode("class P { #items; #total; fn f() { #items[] = 1; } }\n\$rows = [P()];\n\$rows[0].total = 5;")
        );
    }

    private const SHAPES = <<<'CODE'
        abstract class Shape {
            #name;
            #history = [];
            fn _($name) { #name = $name; }
            abstract fn area();
            fn describe() { return "{#name} with area {#area()}"; }
            fn scaled($by = 1) { return #area() * $by; }
        }
        class Circle extends Shape {
            #radius;
            fn _($radius) {
                ##_("circle");
                #radius = $radius;
            }
            fn area() { return 3 * #radius * #radius; }
            fn describe() { return ##describe() .. " (r = {#radius})"; }
            fn plain() { return ##describe; }
        }
        class Unit extends Circle {}
        class Square extends Shape {
            #side = 2;
            fn _() { #history[] = "made"; }
            fn area() { return #side * #side; }
            fn scaled($by = 1, $extra = 0) { return ##scaled($by) + $extra; }
        }

        CODE;

    public function test_inheritance_overrides_and_parent_methods()
    {
        $this->assertEquals(
            "circle with area 12 (r = 2)\ncircle with area 3 (r = 1)\nfunction Shape.describe\ncircle with area 12\n"
            ."Circle {#name => \"circle\", #history => [], #radius => 2}\nSquare {#history => [\"made\"], #side => 2}\n9\n",
            $this->executeCode(self::SHAPES.<<<'CODE'
                $c = Circle(2);
                echo $c.describe();
                echo Unit(1).describe();
                $plain = $c.plain();
                echo $plain;
                echo $plain();
                echo $c;
                $s = Square();
                echo $s;
                echo $s.scaled(2, 1);
                CODE)
        );
    }

    public function test_is_a_follows_the_hierarchy()
    {
        $this->assertEquals("[true, true, true, false, false, false, false]\n", $this->executeCode(self::SHAPES.<<<'CODE'
            $u = Unit(1);
            echo [is_a($u, Shape), is_a($u, Circle), is_a($u, Unit), is_a(Circle(1), Unit), is_a(Square(), Circle), is_a(1, Shape), is_a(Circle, Circle)];
            CODE));
    }

    public function test_class_of_gives_the_class_itself()
    {
        // Exactly the class, not its parents: that is what makes it a dispatch key, where
        // is_a() is the subtype test
        $this->assertEquals("[true, false, true, true]\n", $this->executeCode(self::SHAPES.<<<'CODE'
            $u = Unit(1);
            echo [class_of($u) == Unit, class_of($u) == Shape, is_a($u, Shape), class_of($u)(2).radius == 2];
            CODE));
    }

    public function test_class_of_dispatches_a_visitor_written_outside_the_classes()
    {
        $this->assertEquals("circle 3\n", $this->executeCode(self::SHAPES.<<<'CODE'
            fn describe($s) {
                return match (class_of($s)) {
                    Unit => "circle " .. $s.radius,
                    Square => "square",
                    default => "?",
                };
            }
            echo describe(Unit(3));
            CODE));
    }

    public function test_a_class_without_a_constructor_inherits_its_parents()
    {
        $this->assertEquals("Unit {#name => \"circle\", #history => [], #radius => 3}\n", $this->executeCode(self::SHAPES.'echo Unit(3);'));
    }

    public function test_a_child_constructor_that_does_not_call_the_parents_still_gets_its_defaults()
    {
        $this->assertEquals("P {#a => 1, #b => 2}\n", $this->executeCode(<<<'CODE'
            class Base { #a = 1; fn _() { #a = 100; } }
            class P extends Base { #b = 2; fn _() {} }
            echo P();
            CODE));
    }

    public function test_to_string_is_used_wherever_values_become_text()
    {
        $this->assertEquals(
            "circle (r = 2)\ngot circle (r = 2), circle (r = 2)!\n[circle (r = 2), {\"k\" => circle (r = 2)}]\ncircle (r = 2); square\ntrue\nHolder {#item => circle (r = 2)}\nW has 10: W\n",
            $this->executeCode(<<<'CODE'
                class Shape {
                    #name;
                    fn _($name) { #name = $name; }
                    fn to_string() { return #name; }
                }
                class Circle extends Shape {
                    #r;
                    fn _($r) { ##_("circle"); #r = $r; }
                    fn to_string() { return "{##to_string()} (r = {#r})"; }
                }
                class Holder { #item; fn _($item) { #item = $item; } }
                class Account {
                    #owner;
                    #balance = 10;
                    fn _($owner) { #owner = $owner; }
                    fn to_string() { return "{#owner} has {#balance}: {$nickname ?? #owner}"; }
                }
                $c = Circle(2);
                echo $c;
                echo "got {$c}, " .. $c .. "!";
                echo [$c, {"k" => $c}];
                echo join([$c, Shape("square")], "; ");
                echo to_string($c) == "circle (r = 2)";
                echo Holder($c);
                $a = Account("W");
                echo "{$a}";
                CODE)
        );
    }

    public function test_property_paths_interpolate_inside_braces()
    {
        $this->assertEquals("Hi W, you have 10 #fff \$a.owner.txt\n", $this->executeCode(<<<'CODE'
            class Account { #owner = "W"; #balance = 10; }
            $a = Account();
            echo "Hi {$a.owner}, you have {$a.balance} #fff \$a.owner.txt";
            CODE));
    }

    public function test_errors_and_exit_in_to_string_leave_it()
    {
        $this->assertEquals("caught boom at 3\nkept going\n", $this->executeCode(<<<'CODE'
            class Boom {
                fn to_string() {
                    return error("boom");
                }
            }
            try { echo "x" .. Boom(); } catch ($e) { echo "caught {$e.message} at {$e.line}"; }
            echo "kept going";
            CODE));
    }

    public function test_to_string_reads_and_writes_globals()
    {
        $this->assertEquals("g2 g3 3\n", $this->executeCode(<<<'CODE'
            @g = 1;
            class G { fn to_string() { @g += 1; return "g{@g}"; } }
            echo G() .. " " .. G() .. " " .. @g;
            CODE));
    }

    public function test_updates_in_field_defaults_leave_the_constructor_arguments_alone()
    {
        // The VM lowers these into hidden variables in the initialiser's frame, which holds the arguments
        $this->assertEquals("User {#id => 1, #tags => {\"x\" => 5}, #n => 5, #total => 3, #name => \"Werner\", #role => \"admin\"}\nno local\n", $this->executeCode(<<<'CODE'
            @ids = {"user" => 0};
            fn three() { return 3; }
            class User {
                #id = ++@ids["user"];
                #tags = {};
                #n = #tags["x"] ??= 5;
                #total;
                #name;
                #role;
                fn _($name, $role = "admin") {
                    #total = 0;
                    #total += three();
                    #name = $name;
                    #role = $role;
                }
                fn check() { return $local ?? "no local"; }
            }
            echo User("Werner");
            echo User("Bob").check();
            CODE));
    }

    public function test_constructor_locals_start_undefined()
    {
        $this->assertEquals("undefined\n", $this->executeCode(<<<'CODE'
            @ids = {"a" => 0};
            class P {
                #id = ++@ids["a"];
                fn _() { echo $x ?? "undefined"; $x = 1; }
            }
            P();
            CODE));
    }

    /**
     * @dataProvider constructorRecursion
     */
    public function test_runaway_construction_is_located_where_the_object_is_made(string $code, string $message)
    {
        // Through the CLI, which restarts itself without pcov (see FunctionTest)
        foreach (['', '--interpreter'] as $backend) {
            $command = sprintf('printf %%s %s | %s %s %s 2>&1', escapeshellarg($code), escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__.'/../bin/gazlang'), $backend);
            exec($command, $output, $exit_code);

            // The message, then the capped trace: 10 innermost calls, what was left out, 10 outermost
            $this->assertSame($message, $output[0], "with {$backend}");
            $this->assertSame(22, count($output), "with {$backend}");
            $this->assertStringContainsString(' more', $output[11], "with {$backend}");
            $this->assertSame(1, $exit_code);
            $output = [];
        }
    }

    public static function constructorRecursion(): array
    {
        // Constructing takes two levels (the object's initialiser, then _), so the limit lands on either
        return [
            'on the constructor' => ["class A {\n fn _(\$n) {\n  A(\$n + 1);\n }\n}\nfn h() {\n return A(1);\n}\nh();", 'Error: Maximum call depth of 10000 exceeded calling A._ on line 3'],
            'on the initialiser' => ["class A {\n fn _(\$n) {\n  A(\$n + 1);\n }\n}\nA(1);", 'Error: Maximum call depth of 10000 exceeded calling A on line 3'],
        ];
    }

    public function test_runaway_to_string_is_a_gazlang_error()
    {
        // Through the CLI, which restarts itself without pcov (see FunctionTest)
        $code = 'class Loop { fn to_string() { return "{#}"; } } echo Loop();';
        foreach (['', '--interpreter'] as $backend) {
            $command = sprintf('echo %s | %s %s %s 2>&1', escapeshellarg($code), escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__.'/../bin/gazlang'), $backend);
            exec($command, $output, $exit_code);

            $this->assertSame('Error: Maximum call depth of 10000 exceeded calling Loop.to_string on line 1', $output[0], "with {$backend}");
            $this->assertSame(1, $exit_code);
            $output = [];
        }
    }

    public function test_code_for_reading_fields()
    {
        // A field read through # needs no member lookup; ??, compound updates and methods keep theirs
        $this->assertStringContainsString(
            "fn P.f 0 0\nLOAD_FIELD x\nLOAD_THIS\nGET_PROPERTY_QUIET y\nJNN COALESCE_END_0\nPUSH 0\nLABEL COALESCE_END_0\nADD\n"
            ."LOAD_THIS\nGET_PROPERTY_EXISTING x\nPUSH 1\nADD\nSET_FIELD x\nADD\nPUSH 0\nNEW_ARRAY\nLOAD_THIS\nGET_PROPERTY g\nARRAY_PUSH\n",
            $this->generateCode('class P { #x = 1; #y; fn f() { return #x + (#y ?? 0) + (#x += 1) + 0 * len([#g]); } fn g() {} }')
        );
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
            'compound update of an unset field' => ['class P { #x; } $p = P(); $p.x += 1;', 'Property x of P is not set on line 14'],
            'increment of an unset field with #' => ['class P { #x; fn f() { #x++; } } P().f();', 'Property x of P is not set on line 14'],
            'through an unset field' => ['class P { #x; } $p = P(); $p.x[0] = 1;', 'Property x of P is not set on line 14'],
            'assigning to a method' => ['$a = Account("W"); $a.deposit = 1;', 'Cannot assign to method Account.deposit on line 14'],
            'updating a method' => ['$a = Account("W"); $a.deposit += 1;', 'Cannot assign to method Account.deposit on line 14'],
            'assigning to an undeclared member' => ['$a = Account("W"); $a.nope = 1;', 'Account has no member nope on line 14'],
            'assigning to the constructor' => ['$a = Account("W"); $a._ = 1;', 'Cannot use the constructor of Account as a member on line 14'],
            'a dot on a list in a path' => ['$l = [1]; $l.x = 1;', 'Cannot use . on list on line 14'],
            'a dot on an element in a path' => ['$l = [1]; $l[0].x = 1;', 'Cannot use . on int on line 14'],
            'undefined variable' => ['$nope.x = 1;', 'Undefined variable: $nope on line 14'],
            'undeclared member under ??' => ['echo Account("W").nope ?? 1;', 'Account has no member nope on line 14'],
            'keys and value run before the path fails' => ['fn k() { echo "k"; return 0; } $a = Account("W"); $a.nope[k()] = error("value");', 'value'],
            'constructing an abstract class through a value' => ['abstract class S {} $s = S; $s();', 'Cannot construct abstract class S on line 14'],
            'is_a needs a class' => ['echo is_a(1, "Account");', 'is_a() expects class, got string on line 14'],
            'class_of needs an object' => ['echo class_of(1);', 'class_of() expects object, got int on line 14'],
            'class_of of a class' => ['echo class_of(Account);', 'class_of() expects object, got class on line 14'],
            'to_string returning something else' => ['class P { fn to_string() { return [1]; } } echo P();', 'P.to_string must return a string, got list on line 14'],
            'to_string returning something else, through ..' => ['class P { fn to_string() { return null; } } $s = "a" .. P();', 'P.to_string must return a string, got null on line 14'],
            'error in the constructor' => ["class P {\n fn _() { error(\"no\"); }\n}\n\$p = P();", 'no'],
        ];
    }

    public function test_errors_in_objects_are_caught()
    {
        $this->assertEquals("Property x of P is not set 1\n", $this->executeCode(<<<'CODE'
            class P { #x; fn get() { return #x; } }
            try { P().get(); } catch ($e) { echo $e.message .. " " .. $e.line; }
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
            'duplicate field' => ["class P {\n #x;\n #x = 1;\n}", 'P already has a field #x on line 3'],
            'duplicate method' => ['class P { fn x() {} fn x() {} }', 'P already has a method x on line 1'],
            'method named like a field' => ['class P { #x; fn x() {} }', "P already has a field #x, and a method can't have a field's name: call the method something else on line 1"],
            'field named like a method' => ['class P { fn summary() {} #summary; }', "P already has a method summary, and a field can't have a method's name: call the field something else, like #summary_value on line 1"],
            'constructor returning a value' => ['class P { fn _() { return 1; } }', "A constructor can't return a value: constructing gives the object on line 1"],
            'a lambda in a constructor may return' => ['class P { fn _() { $f = () -> { return 1; }; return; } } echo P() .. ;', "Unexpected ';' on line 1"],
            'local variable in a field default' => ["\$y = 1;\nclass P { #x = \$y; }", "The default of #x can't use \$y: fields have no local variables on line 2"],
            'class named like a builtin' => ['class len {}', 'len is a builtin function on line 1'],
            'class named like a function' => ['fn P() {} class P {}', 'Function P is already declared on line 1'],
            'function named like a class' => ['class P {} fn P() {}', 'Class P is already declared on line 1'],
            'constructing with the wrong argument count' => ["class P { fn _(\$a) {} }\n\nP();", 'Class P expects 1 arguments, 0 given on line 3'],
            'constructing without a constructor' => ['class P {} P(1);', 'Class P expects 0 arguments, 1 given on line 1'],
            'method call arity' => ['class P { fn f($a) { return #f(); } }', 'Method P.f expects 1 arguments, 0 given on line 1'],
            'assigning to a call' => ['class P { #x; } fn make() { return P(); } make().x = 1;', 'Can only use = on a variable, or an element or field of one on line 1'],
            'assigning to #' => ['class P { fn f() { # = 1; } }', 'Can only use = on a variable, or an element or field of one on line 1'],
            'incrementing a method' => ['class P { fn f() { #f++; } }', 'Cannot assign to method #f on line 1'],
            'unknown parent' => ["\nclass P extends Nope {}", 'Undefined class: Nope on line 2'],
            'function as a parent' => ['fn f() {} class P extends f {}', 'f is a function, not a class on line 1'],
            'circular inheritance' => ["class A extends B {}\nclass B extends C {}\nclass C extends B {}", 'Circular inheritance: B extends C extends B on line 3'],
            'a class extending itself' => ['class A extends A {}', 'Circular inheritance: A extends A on line 1'],
            'redeclared field' => ["class A { #x; }\nclass B extends A {\n #x = 1;\n}", 'Field #x of B is already declared in A on line 3'],
            'field named like a parent method' => ['class A { fn x() {} } class B extends A { #x; }', 'Field #x of B has the name of a method of A: fields and methods share names, so call the field something else on line 1'],
            'method named like a parent field' => ['class A { #x; } class B extends A { fn x() {} }', 'Method B.x has the name of a field of A: fields and methods share names, so call the method something else on line 1'],
            'override with fewer arguments' => ["class A { fn f(\$a, \$b = 1) {} }\nclass B extends A {\n fn f(\$a) {}\n}", 'Method B.f must accept every argument count A.f does (1 to 2) on line 3'],
            'override requiring more arguments' => ['class A { fn f() {} } class B extends A { fn f($a) {} }', 'Method B.f must accept every argument count A.f does (0) on line 1'],
            'constructors may differ' => ['class A { fn _($a) {} } class B extends A { fn _($a, $b) { ##_($a); } } B(1);', 'Class B expects 2 arguments, 1 given on line 1'],
            'abstract method in a concrete class' => ["class A {\n abstract fn f();\n}", 'Class A has abstract method f, so it must be abstract too on line 2'],
            'abstract method with a body' => ['abstract class A { abstract fn f() {} }', 'An abstract method has no body: end it with ; on line 1'],
            'abstract constructor' => ['abstract class A { abstract fn _(); }', "A constructor can't be abstract on line 1"],
            'abstract method not defined' => ["abstract class A { abstract fn f(); }\nclass B extends A {}", 'Class B must define abstract method f of A, or be abstract on line 2'],
            'abstract method replacing a method' => ['class A { fn f() {} } abstract class B extends A { abstract fn f(); }', "Abstract method B.f can't replace A.f on line 1"],
            'constructing an abstract class' => ["abstract class A {}\n\nA();", 'Cannot construct abstract class A on line 3'],
            'abstract class inside a block' => ['if (true) { abstract class A {} }', 'Classes can only be declared at the top level on line 1'],
            '## outside a class' => ['fn f() { return ##g(); }', 'Cannot use ##g outside a method on line 1'],
            '## without a parent' => ['class A { fn f() { return ##f(); } }', 'Cannot use ##f: A has no parent class on line 1'],
            '## on a field' => ['class A { #x; } class B extends A { fn f() { return ##x; } }', '##x can only reach a method, and x is a field of A on line 1'],
            '## on an abstract method' => ['abstract class A { abstract fn f(); } class B extends A { fn f() { return ##f(); } }', 'Cannot use ##f: f is abstract in A on line 1'],
            '## on a missing method' => ['class A {} class B extends A { fn f() { return ##f(); } }', 'A has no method f on line 1'],
            '## arity' => ['class A { fn f($a) {} } class B extends A { fn f($a) { return ##f(); } }', 'Method A.f expects 1 arguments, 0 given on line 1'],
            '##_ outside a constructor' => ['class A { fn _() {} } class B extends A { fn f() { ##_(); } }', "##_ can only be used in a constructor, to run the parent's on line 1"],
            '##_ in a lambda in a constructor' => ['class A { fn _() {} } class B extends A { fn _() { $f = () -> ##_(); } }', "##_ can only be used in a constructor, to run the parent's on line 1"],
            '##_ without a call' => ['class A { fn _() {} } class B extends A { fn _() { $f = ##_; } }', "Call the parent's constructor as ##_(...) on line 1"],
            '##_ without a parent constructor' => ['class A {} class B extends A { fn _() { ##_(); } }', 'A has no constructor to call with ##_ on line 1'],
            'to_string with a required argument' => ["class P {\n fn to_string(\$a) {}\n}", 'Method P.to_string must accept 0 arguments: printing calls it with none on line 2'],
            'to_string with only optional arguments is fine' => ['class P { fn to_string($a = 1) { return ""; } } echo ;', "Unexpected ';' on line 1"],
            '{#name} outside a method' => ['echo "{#name}";', 'Cannot use #name outside a method on line 1'],
            'something else in a class body' => ['class P { echo 1; }', "Expected a field (#name) or a method (fn) but found 'echo' on line 1"],
            'duplicate method parameter' => ['class P { fn f($a, $a) {} }', 'Duplicate parameter $a in method P.f on line 1'],
        ];
    }
}
