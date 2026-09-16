<?php

namespace GazLang\Tests;

class NullTest extends GazLangTestCase
{
    public function test_null_prints_and_concatenates_as_null()
    {
        $this->assertEquals("null\nxnull\nnullx\n", $this->executeCode('echo null; echo "x" + null; echo null + "x";'));
    }

    public function test_null_only_equals_null()
    {
        $this->assertEquals("true\nfalse\nfalse\nfalse\ntrue\ntrue\nfalse\n", $this->executeCode(
            'echo null == null; echo null == 0; echo null == false; echo "" == null;'
            .' echo null != 0; echo null === null; echo null === false;'
        ));
    }

    public function test_null_is_false_in_conditions()
    {
        $this->assertEquals("else\ntrue\nfalse\n", $this->executeCode(
            'if (null) { echo "if"; } else { echo "else"; } echo !null; echo null || false;'
        ));
    }

    public function test_variable_holding_null_is_defined()
    {
        $this->assertEquals("null\n", $this->executeCode('$x = null; echo $x;'));
    }

    public function test_arithmetic_on_null_throws()
    {
        $this->expectExceptionMessage('Cannot use + on null');
        $this->executeCode('echo null + 1;');
    }

    public function test_ordering_null_throws()
    {
        $this->expectExceptionMessage('Cannot use < on null');
        $this->executeCode('echo null < 1;');
    }

    public function test_negating_null_throws()
    {
        $this->expectExceptionMessage('Cannot use - on null');
        $this->executeCode('echo -null;');
    }

    public function test_code_gen_pushes_null()
    {
        $this->assertEquals("PUSH null\nPRINT", $this->generateCode('echo null;'));
    }
}
