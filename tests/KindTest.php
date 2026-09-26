<?php

namespace GazLang\Tests;

class KindTest extends GazLangTestCase
{
    private const ACCOUNT = <<<'CODE'
        kind Account {
            pub #owner;
            pub #balance = 0;
            #history = [];
            fn _($owner, $balance = 10) { #owner = $owner; #balance = $balance; }
            pub fn deposit($amount) {
                #balance = #balance + $amount;
                $h = #history; $h[] = $amount; #history = $h;
                return #;
            }
            pub fn total() { return #balance; }
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
            kind Counter {
                #n = 0;
                pub #step;
                fn _($step) { #step = $step; }
                pub fn tick() { #n = #n + #step; return #; }
                pub fn value() { return #n; }
                pub fn twice() { #tick(); return #tick(); }
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
            kind Bag {
                pub #items = [];
                #a = 2;
                #b = #a * 2;
                pub fn add($x) { #items = [#items, $x]; #items = [$x]; }
                pub fn list() { return #items; }
                pub fn both() { return {"a" => #a, "b" => #b}; }
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

    public function test_kinds_and_objects_as_values()
    {
        $this->assertEquals(
            "[\"kind\", \"object\"]\nkind Point\n[kind Point]\ntrue\ntrue\nyes\nPoint {#x => 1}\nPoint {}\n",
            $this->executeCode(<<<'CODE'
                kind Point {
                    #x;
                    #y;
                    pub fn set() { #x = 1; return #; }
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
            kind Node {
                #name;
                #next;
                fn _($name) { #name = $name; #next = #; }
                pub fn point($to) { #next = $to; }
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
            kind Scaler {
                #factor;
                #count = 0;
                fn _($factor) { #factor = $factor; }
                fn scale($x) { #count = #count + 1; return $x * #factor; }
                pub fn all($xs) { return map($xs, #scale); }
                pub fn counter() { return () -> #count = #count + 1; }
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
            kind Button {
                #on_click;
                fn _($handler) { #on_click = $handler; }
                pub fn click($x) { return #on_click($x); }
            }
            echo Button($x -> $x * 3).click(2);
            CODE));
    }

    public function test_keywords_as_member_names()
    {
        $this->assertEquals("class\n", $this->executeCode(<<<'CODE'
            kind Tag {
                #class = "class";
                fn echo() { return #class; }
                pub fn if() { return #echo(); }
            }
            echo Tag().if();
            CODE));
    }

    public function test_writing_fields_through_hash()
    {
        $this->assertEquals("[1, 2] {\"a\" => 2} 2 5 4\n7 [] 7 {\"k\" => [1]}\n", $this->executeCode(<<<'CODE'
            kind Stats {
                #items = [];
                #counts = {"a" => 0};
                #n = 0;
                #lazy;
                #cache = {};
                pub fn run() {
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
                pub fn lazily() {
                    $first = #lazy ??= 7;
                    $second = #lazy ??= throw "not run";
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
            kind P {
                pub #x;
                pub #tags = [];
                pub #next;
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
            kind Bag { pub #items = []; }
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
            kind P { pub #x; pub #y = 1; }
            $p = P();
            $none = null;
            $m = {};
            echo ($p.x ?? "default") .. " " .. ($none.x ?? "null-target") .. " " .. ($m["k"].x ?? "missing-key") .. " " .. ($p.y ?? 2);
            CODE));
    }

    public function test_appending_to_a_field_is_linear()
    {
        $this->assertEquals("20000\n", $this->executeCode(<<<'CODE'
            kind Bag {
                pub #items = [];
                pub fn fill($n) { for ($i = 0; $i < $n; $i++) { #items[] = $i; } return len(#items); }
            }
            echo Bag().fill(20000);
            CODE));
    }

    public function test_code_for_paths_through_fields()
    {
        $this->assertStringEndsWith(
            "POP\nPUSH 0\nKEY_CHECK\nPUSH 5\nSET_PATH [k].total 0\nPOP\nkind P\nLOAD_THIS\nRET\nfn P.f 0 0\nPUSH 1\nSET_PATH_THIS .items[]\nPOP\nPUSH null\nRET",
            $this->generateCode("kind P { #items; #total; pub fn f() { #items[] = 1; } }\n\$rows = [P()];\n\$rows[0].total = 5;")
        );
    }

    private const SHAPES = <<<'CODE'
        abstract kind Shape {
            #name;
            pub #history = [];
            fn _($name) { #name = $name; }
            pub abstract fn area();
            pub fn describe() { return "{#name} with area {#area()}"; }
            pub fn scaled($by = 1) { return #area() * $by; }
        }
        kind Circle extends Shape {
            pub #radius;
            fn _($radius) {
                ##_("circle");
                #radius = $radius;
            }
            pub fn area() { return 3 * #radius * #radius; }
            pub fn describe() { return ##describe() .. " (r = {#radius})"; }
            pub fn plain() { return ##describe; }
        }
        kind Unit extends Circle {}
        kind Square extends Shape {
            #side = 2;
            fn _() { #history[] = "made"; }
            pub fn area() { return #side * #side; }
            pub fn scaled($by = 1, $extra = 0) { return ##scaled($by) + $extra; }
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

    public function test_kind_of_gives_the_kind_itself()
    {
        // Exactly the kind, not its parents: that is what makes it a dispatch key, where
        // is_a() is the subtype test
        $this->assertEquals("[true, false, true, true]\n", $this->executeCode(self::SHAPES.<<<'CODE'
            $u = Unit(1);
            echo [kind_of($u) == Unit, kind_of($u) == Shape, is_a($u, Shape), kind_of($u)(2).radius == 2];
            CODE));
    }

    public function test_kind_name_is_the_name_a_kind_was_declared_with()
    {
        // Namespace included, as echo shows it: the bare name is the last part after ::, and
        // there would be no way back from it. An object's is its kind's.
        $this->assertEquals("Unit Shape Error string\ntui::Rect Rect\n", $this->executeCode(self::SHAPES.<<<'CODE'
            echo kind_name(Unit(1)) .. " " .. kind_name(Shape) .. " " .. kind_name(Error) .. " " .. type_of(kind_name(Unit));
            include "std/tui.gaz";
            echo kind_name(tui::Rect) .. " " .. last(split(kind_name(tui::Rect), "::"));
            CODE));
    }

    public function test_kind_of_dispatches_a_visitor_written_outside_the_kinds()
    {
        $this->assertEquals("circle 3\n", $this->executeCode(self::SHAPES.<<<'CODE'
            fn describe($s) {
                return match (kind_of($s)) {
                    Unit => "circle " .. $s.radius,
                    Square => "square",
                    default => "?",
                };
            }
            echo describe(Unit(3));
            CODE));
    }

    /**
     * Only the fields that are set, in the order echo prints them: the parent's first, each
     * kind's in declaration order, whatever order they were set in
     */
    public function test_fields_gives_the_set_fields_by_name_in_layout_order()
    {
        $this->assertEquals("{\"a\" => 3, \"c\" => [1], \"b\" => 2}\n{}\n", $this->executeCode(<<<'CODE'
            kind Base { pub #a; #unset; }
            kind P extends Base { #c = [1]; #b; fn _() { #b = 2; #a = 3; } }
            echo fields(P());
            kind Empty {}
            echo fields(Empty());
            CODE));
    }

    public function test_fields_is_a_copy()
    {
        $this->assertEquals("P {#a => 1}\n{\"a\" => 2}\n", $this->executeCode(<<<'CODE'
            kind P { #a = 1; }
            $p = P();
            $f = fields($p);
            $f["a"] = 2;
            echo $p;
            echo $f;
            CODE));
    }

    public function test_fields_walks_a_tree_without_knowing_its_kinds()
    {
        $this->assertEquals("6\n", $this->executeCode(<<<'CODE'
            kind Num { pub #value; fn _($v) { #value = $v; } }
            kind Add { #left; #right; fn _($l, $r) { #left = $l; #right = $r; } }
            fn evaluate($node) {
                if (is_a($node, Num)) { return $node.value; }
                $total = 0;
                foreach (fields($node) as $child) { $total += evaluate($child); }
                return $total;
            }
            echo evaluate(Add(Num(1), Add(Num(2), Num(3))));
            CODE));
    }

    public function test_a_kind_without_a_constructor_inherits_its_parents()
    {
        $this->assertEquals("Unit {#name => \"circle\", #history => [], #radius => 3}\n", $this->executeCode(self::SHAPES.'echo Unit(3);'));
    }

    public function test_a_child_constructor_that_does_not_call_the_parents_still_gets_its_defaults()
    {
        $this->assertEquals("P {#a => 1, #b => 2}\n", $this->executeCode(<<<'CODE'
            kind Base { pub #a = 1; fn _() { #a = 100; } }
            kind P extends Base { #b = 2; fn _() {} }
            echo P();
            CODE));
    }

    public function test_to_string_is_used_wherever_values_become_text()
    {
        $this->assertEquals(
            "circle (r = 2)\ngot circle (r = 2), circle (r = 2)!\n[circle (r = 2), {\"k\" => circle (r = 2)}]\ncircle (r = 2); square\ntrue\nHolder {#item => circle (r = 2)}\nW has 10: W\n",
            $this->executeCode(<<<'CODE'
                kind Shape {
                    #name;
                    fn _($name) { #name = $name; }
                    pub fn to_string() { return #name; }
                }
                kind Circle extends Shape {
                    #r;
                    fn _($r) { ##_("circle"); #r = $r; }
                    pub fn to_string() { return "{##to_string()} (r = {#r})"; }
                }
                kind Holder { #item; fn _($item) { #item = $item; } }
                kind Account {
                    pub #owner;
                    pub #balance = 10;
                    fn _($owner) { #owner = $owner; }
                    pub fn to_string() { return "{#owner} has {#balance}: {$nickname ?? #owner}"; }
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
            kind Account { pub #owner = "W"; pub #balance = 10; }
            $a = Account();
            echo "Hi {$a.owner}, you have {$a.balance} #fff \$a.owner.txt";
            CODE));
    }

    public function test_errors_and_exit_in_to_string_leave_it()
    {
        $this->assertEquals("caught boom at 3\nkept going\n", $this->executeCode(<<<'CODE'
            kind Boom {
                pub fn to_string() {
                    throw "boom";
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
            kind G { pub fn to_string() { @g += 1; return "g{@g}"; } }
            echo G() .. " " .. G() .. " " .. @g;
            CODE));
    }

    public function test_updates_in_field_defaults_leave_the_constructor_arguments_alone()
    {
        // The VM lowers these into hidden variables in the initialiser's frame, which holds the arguments
        $this->assertEquals("User {#id => 1, #tags => {\"x\" => 5}, #n => 5, #total => 3, #name => \"Werner\", #role => \"admin\"}\nno local\n", $this->executeCode(<<<'CODE'
            @ids = {"user" => 0};
            fn three() { return 3; }
            kind User {
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
                pub fn check() { return $local ?? "no local"; }
            }
            echo User("Werner");
            echo User("Bob").check();
            CODE));
    }

    public function test_constructor_locals_start_undefined()
    {
        $this->assertEquals("undefined\n", $this->executeCode(<<<'CODE'
            @ids = {"a" => 0};
            kind P {
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
        [$output, $exit_code] = self::cli([], $code);

        // The message, then the capped trace: 10 innermost calls, what was left out, 10 outermost
        $this->assertSame($message, $output[0]);
        $this->assertSame(22, count($output));
        $this->assertStringContainsString(' more', $output[11]);
        $this->assertSame(1, $exit_code);
    }

    public static function constructorRecursion(): array
    {
        // Constructing takes two levels (the object's initialiser, then _), so the limit lands on either
        return [
            'on the constructor' => ["kind A {\n fn _(\$n) {\n  A(\$n + 1);\n }\n}\nfn h() {\n return A(1);\n}\nh();", 'Error: Maximum call depth of 10000 exceeded calling A._ on line 3'],
            'on the initialiser' => ["kind A {\n fn _(\$n) {\n  A(\$n + 1);\n }\n}\nA(1);", 'Error: Maximum call depth of 10000 exceeded calling A on line 3'],
        ];
    }

    public function test_runaway_to_string_is_a_gazlang_error()
    {
        $code = 'kind Loop { pub fn to_string() { return "{#}"; } } echo Loop();';
        [$output, $exit_code] = self::cli([], $code);

        $this->assertSame('Error: Maximum call depth of 10000 exceeded calling Loop.to_string on line 1', $output[0]);
        $this->assertSame(1, $exit_code);
    }

    public function test_code_for_reading_fields()
    {
        // A field read through # needs no member lookup; ??, compound updates and methods keep theirs
        $this->assertStringContainsString(
            "fn P.f 0 0\nLOAD_FIELD x\nLOAD_THIS\nGET_PROPERTY_QUIET y\nJNN COALESCE_END_0\nPUSH 0\nLABEL COALESCE_END_0\nADD\n"
            ."LOAD_THIS\nGET_PROPERTY_EXISTING x\nPUSH 1\nADD\nSET_FIELD x\nADD\nPUSH 0\nNEW_ARRAY\nLOAD_THIS\nGET_PROPERTY g\nARRAY_PUSH\n",
            $this->generateCode('kind P { pub #x = 1; pub #y; pub fn f() { return #x + (#y ?? 0) + (#x += 1) + 0 * len([#g]); } fn g() {} }')
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
            'unset field' => ['kind P { pub #x; pub fn get() { return #x; } } echo P().get();', 'Property x of P is not set on line 14'],
            'unset field read with a dot' => ['kind P { pub #x; } echo P().x;', 'Property x of P is not set on line 14'],
            'undeclared member' => ['echo Account("W").nope;', 'Account has no member nope on line 14'],
            'undeclared member called, before the arguments' => ['Account("W").nope(throw "args");', 'Account has no member nope on line 14'],
            'unset field called, before the arguments' => ['kind P { pub #f; } P().f(throw "args");', 'Property f of P is not set on line 14'],
            'constructor as a member' => ['$a = Account("W"); $a._("X");', 'Cannot use the constructor of Account as a member on line 14'],
            'dot on a map' => ['echo {"a" => 1}.a;', 'Cannot use . on map on line 14'],
            'dot on null' => ['$x = null; echo $x.a;', 'Cannot use . on null on line 14'],
            'method arity' => ['Account("W").deposit(1, 2);', 'Method Account.deposit expects 1 arguments, 2 given on line 14'],
            'bound method arity' => ['$d = Account("W").deposit; $d();', 'Method Account.deposit expects 1 arguments, 0 given on line 14'],
            'kind value arity' => ['$m = Account; $m();', 'Kind Account expects 1 to 2 arguments, 0 given on line 14'],
            // A builtin that calls back checks a kind as CALL_VALUE does, with its own copy
            'kind value arity through a builtin' => ['kind P { #a; #b; fn _($a, $b) {} } echo map([1, 2], P);', 'Kind P expects 2 arguments, 1 given on line 14'],
            'abstract kind through a builtin' => ['abstract kind S { pub abstract fn area(); } echo map([1], S);', 'Cannot construct abstract kind S on line 14'],
            'calling a field that is not a function' => ['kind P { pub #x = 1; } P().x();', 'Cannot call int on line 14'],
            'arithmetic' => ['echo Account("W") + 1;', 'Cannot use + on object on line 14'],
            'ordering' => ['echo Account < 1;', 'Cannot use < on kind on line 14'],
            'key' => ['echo {Account("W") => 1};', 'Keys must be int or string, got object on line 14'],
            'index' => ['echo Account("W")[0];', 'Cannot use [] on object on line 14'],
            'foreach' => ['foreach (Account("W") as $x) {}', 'foreach expects a list or map, got object on line 14'],
            'error in a field default, located there' => ["kind P {\n pub #x = 1 / 0;\n}\nP();", 'Division by zero on line 15'],
            'compound update of an unset field' => ['kind P { pub #x; } $p = P(); $p.x += 1;', 'Property x of P is not set on line 14'],
            'increment of an unset field with #' => ['kind P { pub #x; pub fn f() { #x++; } } P().f();', 'Property x of P is not set on line 14'],
            'through an unset field' => ['kind P { pub #x; } $p = P(); $p.x[0] = 1;', 'Property x of P is not set on line 14'],
            'assigning to a method' => ['$a = Account("W"); $a.deposit = 1;', 'Cannot assign to method Account.deposit on line 14'],
            'updating a method' => ['$a = Account("W"); $a.deposit += 1;', 'Cannot assign to method Account.deposit on line 14'],
            'assigning to an undeclared member' => ['$a = Account("W"); $a.nope = 1;', 'Account has no member nope on line 14'],
            'assigning to the constructor' => ['$a = Account("W"); $a._ = 1;', 'Cannot use the constructor of Account as a member on line 14'],
            'a dot on a list in a path' => ['$l = [1]; $l.x = 1;', 'Cannot use . on list on line 14'],
            'a dot on an element in a path' => ['$l = [1]; $l[0].x = 1;', 'Cannot use . on int on line 14'],
            'undefined variable' => ['$nope.x = 1;', 'Undefined variable: $nope on line 14'],
            'undeclared member under ??' => ['echo Account("W").nope ?? 1;', 'Account has no member nope on line 14'],
            'keys and value run before the path fails' => ['fn k() { echo "k"; return 0; } $a = Account("W"); $a.nope[k()] = throw "value";', 'value'],
            'constructing an abstract kind through a value' => ['abstract kind S {} $s = S; $s();', 'Cannot construct abstract kind S on line 14'],
            'is_a needs a kind' => ['echo is_a(1, "Account");', 'is_a() expects kind, got string on line 14'],
            'kind_of needs an object' => ['echo kind_of(1);', 'kind_of() expects object, got int on line 14'],
            'kind_of of a kind' => ['echo kind_of(Account);', 'kind_of() expects object, got kind on line 14'],
            'kind_name of a string' => ['echo kind_name("Account");', 'kind_name() expects kind or object, got string on line 14'],
            'fields needs an object' => ['echo fields({"a" => 1});', 'fields() expects object, got map on line 14'],
            'to_string returning something else' => ['kind P { pub fn to_string() { return [1]; } } echo P();', 'P.to_string must return a string, got list on line 14'],
            'to_string returning something else, through ..' => ['kind P { pub fn to_string() { return null; } } $s = "a" .. P();', 'P.to_string must return a string, got null on line 14'],
            'error in the constructor' => ["kind P {\n fn _() { throw \"no\"; }\n}\n\$p = P();", 'no'],
        ];
    }

    public function test_errors_in_objects_are_caught()
    {
        $this->assertEquals("Property x of P is not set 1\n", $this->executeCode(<<<'CODE'
            kind P { pub #x; pub fn get() { return #x; } }
            try { P().get(); } catch ($e) { echo $e.message .. " " .. $e.line; }
            CODE));
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
            'reserved word' => ["\$x = 1;\ninterface Shape {}", 'interface is reserved on line 2'],
            'reserved word in an expression' => ['echo private;', "private is reserved: a member says nothing to be its kind's own on line 1"],
            'a reserved word that names a level' => ['echo protected;', "protected is reserved: write 'kin' on line 1"],
            'dot after #' => ['kind P { pub fn f() { return #.name; } }', 'Write #name, not #.name on line 1'],
            'bare ##' => ['echo ##;', "## alone is not allowed: write ##name for the parent's version of a method on line 1"],
            'kind inside a block' => ['if (true) { kind P {} }', 'Kinds can only be declared at the top level on line 1'],
            '# outside a method' => ["\necho #;", 'Cannot use # outside a method on line 2'],
            '#name outside a method' => ['fn f() { return #x; }', 'Cannot use #x outside a method on line 1'],
            '#name in a lambda outside a kind' => ['$f = () -> #x;', 'Cannot use #x outside a method on line 1'],
            'undeclared member' => ["kind P {\n pub fn f() { return #nope; }\n}", 'P has no member #nope on line 2'],
            'assigning to a method' => ['kind P { pub fn f() { #f = 1; } }', 'Cannot assign to method #f on line 1'],
            'the constructor as a member' => ['kind P { fn _() {} pub fn f() { #_(); } }', "Cannot use the constructor _ as a member: construct with P(...), or call ##_(...) in a child's constructor on line 1"],
            'duplicate field' => ["kind P {\n pub #x;\n pub #x = 1;\n}", 'P already has a field #x on line 3'],
            'duplicate method' => ['kind P { pub fn x() {} pub fn x() {} }', 'P already has a method x on line 1'],
            'method named like a field' => ['kind P { pub #x; pub fn x() {} }', "P already has a field #x, and a method can't have a field's name: call the method something else on line 1"],
            'field named like a method' => ['kind P { fn summary() {} #summary; }', "P already has a method summary, and a field can't have a method's name: call the field something else, like #summary_value on line 1"],
            'constructor returning a value' => ['kind P { fn _() { return 1; } }', "A constructor can't return a value: constructing gives the object on line 1"],
            'a lambda in a constructor may return' => ['kind P { fn _() { $f = () -> { return 1; }; return; } } echo P() .. ;', "Unexpected ';' on line 1"],
            'local variable in a field default' => ["\$y = 1;\nkind P { pub #x = \$y; }", "The default of #x can't use \$y: fields have no local variables on line 2"],
            'kind named like a builtin' => ['kind len {}', 'len is a builtin function on line 1'],
            'kind named like a function' => ['fn P() {} kind P {}', 'Function P is already declared on line 1'],
            'function named like a kind' => ['kind P {} fn P() {}', 'Kind P is already declared on line 1'],
            'constructing with the wrong argument count' => ["kind P { fn _(\$a) {} }\n\nP();", 'Kind P expects 1 arguments, 0 given on line 3'],
            'constructing without a constructor' => ['kind P {} P(1);', 'Kind P expects 0 arguments, 1 given on line 1'],
            'method call arity' => ['kind P { pub fn f($a) { return #f(); } }', 'Method P.f expects 1 arguments, 0 given on line 1'],
            'assigning to an element of a call' => ['fn make() { return [1]; } make()[0] = 1;', 'Can only use = on a variable, or an element or field of one on line 1'],
            'assigning to #' => ['kind P { pub fn f() { # = 1; } }', 'Can only use = on a variable, or an element or field of one on line 1'],
            'incrementing a method' => ['kind P { pub fn f() { #f++; } }', 'Cannot assign to method #f on line 1'],
            'unknown parent' => ["\nkind P extends Nope {}", 'Undefined kind: Nope on line 2'],
            'function as a parent' => ['fn f() {} kind P extends f {}', 'f is a function, not a kind on line 1'],
            'circular inheritance' => ["kind A extends B {}\nkind B extends C {}\nkind C extends B {}", 'Circular inheritance: B extends C extends B on line 3'],
            'a kind extending itself' => ['kind A extends A {}', 'Circular inheritance: A extends A on line 1'],
            'redeclared field' => ["kind A { pub #x; }\nkind B extends A {\n pub #x = 1;\n}", 'Field #x of B is already declared in A on line 3'],
            'field named like a parent method' => ['kind A { pub fn x() {} } kind B extends A { #x; }', 'Field #x of B has the name of a method of A: fields and methods share names, so call the field something else on line 1'],
            'method named like a parent field' => ['kind A { pub #x; } kind B extends A { fn x() {} }', 'Method B.x has the name of a field of A: fields and methods share names, so call the method something else on line 1'],
            'override with fewer arguments' => ["kind A { pub fn f(\$a, \$b = 1) {} }\nkind B extends A {\n pub fn f(\$a) {}\n}", 'Method B.f must accept every argument count A.f does (1 to 2) on line 3'],
            'override requiring more arguments' => ['kind A { pub fn f() {} } kind B extends A { pub fn f($a) {} }', 'Method B.f must accept every argument count A.f does (0) on line 1'],
            'constructors may differ' => ['kind A { fn _($a) {} } kind B extends A { fn _($a, $b) { ##_($a); } } B(1);', 'Kind B expects 2 arguments, 1 given on line 1'],
            'abstract method in a concrete kind' => ["kind A {\n pub abstract fn f();\n}", 'Kind A has abstract method f, so it must be abstract too on line 2'],
            'abstract method with a body' => ['abstract kind A { pub abstract fn f() {} }', 'An abstract method has no body: end it with ; on line 1'],
            'abstract constructor' => ['abstract kind A { abstract fn _(); }', "A constructor can't be abstract on line 1"],
            'abstract method not defined' => ["abstract kind A { pub abstract fn f(); }\nkind B extends A {}", 'Kind B must define abstract method f of A, or be abstract on line 2'],
            'abstract method replacing a method' => ['kind A { pub fn f() {} } abstract kind B extends A { pub abstract fn f(); }', "Abstract method B.f can't replace A.f on line 1"],
            'constructing an abstract kind' => ["abstract kind A {}\n\nA();", 'Cannot construct abstract kind A on line 3'],
            'abstract kind inside a block' => ['if (true) { abstract kind A {} }', 'Kinds can only be declared at the top level on line 1'],
            '## outside a kind' => ['fn f() { return ##g(); }', 'Cannot use ##g outside a method on line 1'],
            '## without a parent' => ['kind A { pub fn f() { return ##f(); } }', 'Cannot use ##f: A has no parent kind on line 1'],
            '## on a field' => ['kind A { pub #x; } kind B extends A { pub fn f() { return ##x; } }', '##x can only reach a method, and x is a field of A on line 1'],
            '## on an abstract method' => ['abstract kind A { pub abstract fn f(); } kind B extends A { pub fn f() { return ##f(); } }', 'Cannot use ##f: f is abstract in A on line 1'],
            '## on a missing method' => ['kind A {} kind B extends A { pub fn f() { return ##f(); } }', 'A has no method f on line 1'],
            '## arity' => ['kind A { pub fn f($a) {} } kind B extends A { pub fn f($a) { return ##f(); } }', 'Method A.f expects 1 arguments, 0 given on line 1'],
            '##_ outside a constructor' => ['kind A { fn _() {} } kind B extends A { pub fn f() { ##_(); } }', "##_ can only be used in a constructor, to run the parent's on line 1"],
            '##_ in a lambda in a constructor' => ['kind A { fn _() {} } kind B extends A { fn _() { $f = () -> ##_(); } }', "##_ can only be used in a constructor, to run the parent's on line 1"],
            '##_ without a call' => ['kind A { fn _() {} } kind B extends A { fn _() { $f = ##_; } }', "Call the parent's constructor as ##_(...) on line 1"],
            '##_ without a parent constructor' => ['kind A {} kind B extends A { fn _() { ##_(); } }', 'A has no constructor to call with ##_ on line 1'],
            'to_string with a required argument' => ["kind P {\n pub fn to_string(\$a) {}\n}", 'Method P.to_string must accept 0 arguments: printing calls it with none on line 2'],
            'to_string with only optional arguments is fine' => ['kind P { pub fn to_string($a = 1) { return ""; } } echo ;', "Unexpected ';' on line 1"],
            '{#name} outside a method' => ['echo "{#name}";', 'Cannot use #name outside a method on line 1'],
            'something else in a kind body' => ['kind P { echo 1; }', "Expected a field (#name), a method (fn), a constant (const) or a static but found 'echo' on line 1"],
            'duplicate method parameter' => ['kind P { pub fn f($a, $a) {} }', 'Duplicate parameter $a in method P.f on line 1'],
        ];
    }

    public function test_a_capitalised_keyword_can_name_a_kind_or_a_function()
    {
        // Keywords are lowercase and matched exactly, so the names a self-hosted AST wants
        // (If, While, Return, Match) are free rather than reserved by every capitalisation
        $this->assertEquals("1\n", $this->executeCode('kind If { pub #x; fn _($x) { #x = $x; } } echo If(1).x;'));
        $this->assertEquals("2\n", $this->executeCode('fn Return($x) { return $x; } echo Return(2);'));
        $this->assertEquals("3\n", $this->executeCode('kind Match { pub fn While() { return 3; } } echo Match().While();'));
    }

    /**
     * @dataProvider miscapitalisedKeywords
     */
    public function test_a_keyword_in_the_wrong_case_says_so(string $code, string $message)
    {
        // Nothing here is a keyword any more, so the errors would otherwise be about names
        $this->expectExceptionMessage($message);
        $this->parse($code);
    }

    public static function miscapitalisedKeywords(): array
    {
        return [
            // The failure is at the token after the name, so the hint looks back one
            'a statement keyword' => ['Return 1;', "Expected ';' but found '1' (keywords are lowercase: write 'return', not 'Return')"],
            'shouting' => ['ECHO "x";', "Expected ';' but found string \"x\" (keywords are lowercase: write 'echo', not 'ECHO')"],
            // A bare name reaches the deferred check instead
            'a literal' => ['echo True;', "Undefined function or constant: True (keywords are lowercase: write 'true', not 'True')"],
            'a call' => ['echo IF(1);', "Undefined function: IF (keywords are lowercase: write 'if', not 'IF')"],
        ];
    }

    public function test_an_ordinary_undefined_name_gets_no_keyword_hint()
    {
        $this->expectExceptionMessage('Undefined function or constant: missing');
        $this->parse('echo missing;');
    }

    /**
     * @dataProvider declaredKeywordNames
     */
    public function test_a_declared_name_gets_no_keyword_hint(string $code)
    {
        // Naming things Return and If is the point of the rule, so an error next to one must
        // not tell the reader to write the keyword they did not mean
        try {
            $this->parse($code);
            $this->fail('expected a parse error');
        } catch (ProgramError $e) {
            $this->assertStringNotContainsString('keywords are lowercase', $e->getMessage());
        }
    }

    public static function declaredKeywordNames(): array
    {
        return [
            'a declared function' => ['fn Return($x) { return $x; } $y = Return'],
            'a declared kind' => ['kind If { pub #x; fn _($x) { #x = $x; } } $y = If'],
            'a builtin' => ['$y = len'],
        ];
    }
}
