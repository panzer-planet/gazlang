<?php

namespace GazLang\Tests;

class IfElseTest extends GazLangTestCase
{
    public function test_if_else()
    {
        $this->assertEquals("42\n", $this->executeCode('if (1) { echo 42; } else { echo 0; }'));
    }

    public function test_if_else_false_condition()
    {
        $this->assertEquals("0\n", $this->executeCode('if (0) { echo 42; } else { echo 0; }'));
    }

    public function test_nested_if_else()
    {
        $this->assertEquals("20\n", $this->executeCode(
            'if (1) { if (0) { echo 10; } else { echo 20; } } else { echo 30; }'
        ));
    }

    public function test_variable_in_condition()
    {
        $this->assertEquals("100\n400\n", $this->executeCode(
            '$x = 5; if ($x) { echo 100; } else { echo 200; }'
            .' $y = 0; if ($y) { echo 300; } else { echo 400; }'
        ));
    }

    public function test_else_if()
    {
        $this->assertEquals("20\n", $this->executeCode(
            'if (0) { echo 10; } else if (1) { echo 20; } else { echo 30; }'
        ));
    }

    public function test_multiple_else_if()
    {
        $this->assertEquals("300\n", $this->executeCode(
            'if (0) { echo 100; } else if (0) { echo 200; } else if (1) { echo 300; } else { echo 400; }'
        ));
    }

    public function test_nested_else_if()
    {
        $this->assertEquals("600\n", $this->executeCode(
            'if (1) { if (0) { echo 500; } else if (1) { echo 600; } else { echo 700; } } else { echo 800; }'
        ));
    }
}
