<?php

namespace GazLang\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * static #count = 0; and static fn next(): a member of the kind rather than of an object,
 * reached by name. tests/corpora/parser/statics.gaz has the parse errors.
 */
class StaticTest extends GazLangTestCase
{
    private const COUNTER = 'kind Counter {
            pub static #count = 0;
            #id;
            fn _() { #count++; #id = #count; }
            pub static fn next() { #count++; return #count; }
            pub fn mine() { return #id; }
        } ';

    public function test_a_static_field_is_one_slot_however_many_objects_there_are()
    {
        $this->assertSame(
            "0\n2\n1 2\n",
            $this->executeCode(self::COUNTER.'echo Counter::count;
                $a = Counter(); $b = Counter();
                echo Counter::count;
                echo $a.mine() .. " " .. $b.mine();')
        );
    }

    public function test_a_child_shares_its_parent_s_static()
    {
        $this->assertSame(
            "1 1\n2 2\n",
            $this->executeCode(self::COUNTER.'kind Tally extends Counter {}
                echo Counter::next() .. " " .. Tally::count;
                echo Tally::next() .. " " .. Counter::count;')
        );
    }

    public function test_a_static_field_can_be_written_from_anywhere_and_written_through()
    {
        $this->assertSame(
            "7\n8\n[\"a\", \"b\"]\n[\"b\"]\n",
            $this->executeCode('kind Reg {
                    pub static #count = 0;
                    pub static #rows = [];
                }
                Reg::count = 7; echo Reg::count;
                Reg::count++; echo Reg::count;
                Reg::rows[] = "a"; Reg::rows[] = "b"; echo Reg::rows;
                delete Reg::rows[0]; echo Reg::rows;')
        );
    }

    public function test_a_static_field_is_written_through_by_hash_inside_the_kind()
    {
        // #rows[...] is a path through the static's slot, from a static method and from an
        // object's method alike, as Reg::rows[...] is from outside
        $this->assertSame(
            "[\"a\", \"b\"]\n{\"x\" => 2, \"y\" => {\"z\" => 1}}\n[\"b\"]\n[\"b\", \"c\"]\n{\"x\" => 3, \"y\" => {\"z\" => 1}}\nab!\n",
            $this->executeCode('kind Reg {
                    pub static #rows = [];
                    pub static #counts = {};
                    pub static #name = "";
                    pub static fn add($row) { #rows[] = $row; return #rows; }
                    pub static fn count($key) {
                        #counts[$key] = (#counts[$key] ?? 0) + 1;
                        #counts[$key] += 0;
                        #counts["y"] = {};
                        #counts["y"]["z"] = 1;
                        return #counts;
                    }
                    pub static fn drop() { delete #rows[0]; return #rows; }
                    pub fn also($row) { #rows[] = $row; return #rows; }
                    pub fn again($key) { #counts[$key]++; return #counts; }
                    pub static fn say($text) { #name ..= $text; return #name; }
                }
                Reg::add("a"); echo Reg::add("b");
                Reg::count("x"); echo Reg::count("x");
                echo Reg::drop();
                echo Reg().also("c");
                echo Reg().again("x");
                Reg::say("a"); Reg::say("b"); echo Reg::say("!");')
        );
    }

    public function test_a_static_method_needs_no_object_and_reaches_the_kind_by_hash()
    {
        $this->assertSame(
            "1 2\n3\n",
            $this->executeCode(self::COUNTER.'kind Pair {
                    pub static fn both() { return Counter::next() .. " " .. Counter::next(); }
                }
                echo Pair::both();
                echo Counter::next();')
        );
    }

    /**
     * A static is reached by name, so a value on the left of it is not one
     */
    #[DataProvider('notThroughAValue')]
    public function test_a_static_is_not_reached_through_a_value(string $code, string $message)
    {
        try {
            $this->executeCode(self::COUNTER.$code);
            $this->fail("Expected an error for {$code}");
        } catch (ProgramError $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public static function notThroughAValue(): array
    {
        return [
            'a field through an object' => ['$c = Counter(); echo $c.count;', 'Counter has no member count'],
            'a method through an object' => ['$c = Counter(); echo $c.next();', 'Counter has no member next'],
            'a kind value on the left of ::' => ['$k = Counter; echo $k::count;', ':: resolves a name, so only a name can be on its left'],
        ];
    }
}
