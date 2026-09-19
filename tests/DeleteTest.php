<?php

namespace GazLang\Tests;

/**
 * delete removes an element of a list or map; tests/gaz/lists/delete_test.gaz covers
 * the semantics on both backends, so these are the parse errors and the code
 */
class DeleteTest extends GazLangTestCase
{
    public function test_a_list_closes_the_gap_and_a_map_keeps_its_order()
    {
        $this->assertSame(
            "[1, 3]\n{\"a\" => 1, \"c\" => 3}\n",
            $this->executeCode('$l = [1, 2, 3]; delete $l[1]; echo $l; $m = {"a" => 1, "b" => 2, "c" => 3}; delete $m["b"]; echo $m;')
        );
    }

    public function test_a_list_is_a_stack_by_deleting_its_last_element()
    {
        // The last element takes another path (array_pop, where array_splice rebuilds the list
        // and made every pop cost the whole stack), so the list it leaves is checked to still be one
        $this->assertSame(
            "3 2 [1]\n[1, 4]\n[4]\n[]\n",
            $this->executeCode('$s = [1, 2, 3]; $out = ""; while (len($s) > 1) { $last = len($s) - 1; $out ..= $s[$last] .. " "; delete $s[$last]; } echo $out .. $s; $s[] = 4; echo $s; delete $s[0]; echo $s; delete $s[0]; echo $s;')
        );
    }

    public function test_keys_are_evaluated_before_the_variable_is_read()
    {
        // As an assignment does it: the key's side effects are kept
        $this->assertSame(
            "[1]\n1\n",
            $this->executeCode('$l = [1, 2]; $i = 0; delete $l[$i += 1]; echo $l; echo $i;')
        );
    }

    public function test_deleting_a_variable_is_a_parse_error()
    {
        $this->expectExceptionMessage('delete needs an element of a list or map, like delete $a[0]');
        $this->parse('$m = {"a" => 1}; delete $m;');
    }

    public function test_deleting_what_a_call_returns_is_a_parse_error()
    {
        // A path starts at a variable or #, as an assignment's does: there is nothing to write back to
        $this->expectExceptionMessage('delete needs an element of a list or map, like delete $a[0]');
        $this->parse('fn f() { return [1]; } delete f()[0];');
    }

    public function test_deleting_a_field_is_a_parse_error()
    {
        $this->expectExceptionMessage('Cannot delete a field: every object of a class has the fields it declares');
        $this->parse('class C { #x = 1; fn f() { delete #x; } }');
    }

    public function test_deleting_a_property_of_an_object_is_a_parse_error()
    {
        $this->expectExceptionMessage('Cannot delete a field: every object of a class has the fields it declares');
        $this->parse('class C { #x = 1; } $c = C(); delete $c.x;');
    }

    public function test_delete_is_a_keyword_but_still_a_member_name()
    {
        $this->assertSame("gone\n", $this->executeCode('class C { fn delete() { return "gone"; } } echo C().delete();'));

        $this->expectException(ProgramError::class);
        $this->expectExceptionMessage("Expected a name but found 'delete'");
        $this->parse('fn delete() { return 1; }');
    }

    public function test_code_gen_pushes_the_keys_then_deletes_through_the_path()
    {
        $this->assertSame(
            // The STORE/LOAD/POP of the assignment is collapsed by VM::link(), not here
            "PUSH [1, 2]\nSTORE 0\nLOAD 0\nPOP\nPUSH 0\nKEY_CHECK\nDELETE_PATH [k] 0",
            $this->generateCode('$l = [1, 2]; delete $l[0];')
        );
    }

    public function test_code_gen_for_a_path_through_a_field()
    {
        $this->assertSame(
            "fn C.f 0 0\nPUSH 0\nKEY_CHECK\nDELETE_PATH_THIS .items[k]\nPUSH null\nRET",
            substr($this->generateCode('class C { #items = [1]; fn f() { delete #items[0]; } } C().f();'), -strlen("fn C.f 0 0\nPUSH 0\nKEY_CHECK\nDELETE_PATH_THIS .items[k]\nPUSH null\nRET"))
        );
    }
}
