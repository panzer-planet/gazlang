<?php

namespace GazLang\Tests;

/**
 * static #count = 0; and static fn next(): a member of the class rather than of an object,
 * reached by name. tests/parser_corpus/statics.gaz has the parse errors.
 */
class StaticTest extends GazLangTestCase
{
    private const COUNTER = 'class Counter {
            static #count = 0;
            #id;
            fn _() { #count++; #id = #count; }
            static fn next() { #count++; return #count; }
            fn mine() { return #id; }
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
            $this->executeCode(self::COUNTER.'class Tally extends Counter {}
                echo Counter::next() .. " " .. Tally::count;
                echo Tally::next() .. " " .. Counter::count;')
        );
    }

    public function test_a_static_field_can_be_written_from_anywhere_and_written_through()
    {
        $this->assertSame(
            "7\n8\n[\"a\", \"b\"]\n[\"b\"]\n",
            $this->executeCode('class Reg {
                    static #count = 0;
                    static #rows = [];
                }
                Reg::count = 7; echo Reg::count;
                Reg::count++; echo Reg::count;
                Reg::rows[] = "a"; Reg::rows[] = "b"; echo Reg::rows;
                delete Reg::rows[0]; echo Reg::rows;')
        );
    }

    public function test_a_static_method_needs_no_object_and_reaches_the_class_by_hash()
    {
        $this->assertSame(
            "1 2\n3\n",
            $this->executeCode(self::COUNTER.'class Pair {
                    static fn both() { return Counter::next() .. " " .. Counter::next(); }
                }
                echo Pair::both();
                echo Counter::next();')
        );
    }

    /**
     * A static is reached by name, so a value on the left of it is not one
     *
     * @dataProvider notThroughAValue
     */
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
            'a class value on the left of ::' => ['$k = Counter; echo $k::count;', ':: resolves a name, so only a name can be on its left'],
        ];
    }
}
