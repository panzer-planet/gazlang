<?php

namespace GazLang\Tests;

class StringTest extends GazLangTestCase
{
    public function test_simple_string()
    {
        $code = <<<'CODE'
        echo "Hello, World!";
        CODE;

        $this->assertSame("Hello, World!\n", $this->executeCode($code));
    }

    public function test_string_assignment()
    {
        $code = <<<'CODE'
        $message = "Hello, GazLang!";
        echo $message;
        CODE;

        $this->assertSame("Hello, GazLang!\n", $this->executeCode($code));
    }

    public function test_string_concatenation()
    {
        $code = <<<'CODE'
        echo "Hello, " .. "World!";
        $prefix = "GazLang ";
        $suffix = "is awesome";
        echo $prefix .. $suffix;
        CODE;

        $this->assertSame("Hello, World!\nGazLang is awesome\n", $this->executeCode($code));
    }

    public function test_mixed_type_operations()
    {
        $code = <<<'CODE'
        // .. converts a number the way echo does
        echo "Count: " .. 42;
        
        // On either side
        echo 2022 .. " is the year";
        
        // Using variable
        $year = 2025;
        echo "The year is " .. $year;
        CODE;

        $this->assertSame("Count: 42\n2022 is the year\nThe year is 2025\n", $this->executeCode($code));
    }
}
