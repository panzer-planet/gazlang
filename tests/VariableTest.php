<?php

namespace GazLang\Tests;

/**
 * Tests for variables feature in GazLang
 */
class VariableTest extends GazLangTestCase
{
    /**
     * Test basic variable declaration without echo
     */
    public function test_basic_var_declaration()
    {
        $input = '$x = 5;';
        $interpreter = $this->createInterpreter($input);
        $interpreter->interpret();

        // If we got here without exceptions, test passed
        $this->assertTrue(true);
    }

    /**
     * Test basic variable assignment and usage with echo
     */
    public function test_basic_assignment()
    {
        $input = '$x = 5; echo $x;';
        $output = $this->executeCode($input);

        $this->assertEquals("5\n", $output);
    }

    /**
     * Test variable in simple expression with literal
     */
    public function test_var_with_literal_expression()
    {
        $input = '$x = 5; echo $x + 3;';
        $output = $this->executeCode($input);

        $this->assertEquals("8\n", $output);
    }

    /**
     * Test variable in expression with another variable
     */
    public function test_multiple_vars_expression()
    {
        $input = '$x = 5; $y = 3; echo $x + $y;';
        $output = $this->executeCode($input);

        $this->assertEquals("8\n", $output);
    }

    /**
     * Test variable reassignment
     */
    public function test_variable_reassignment()
    {
        $input = '$x = 10; echo $x; $x = 20; echo $x;';
        $output = $this->executeCode($input);

        $this->assertEquals("10\n20\n", $output);
    }

    /**
     * Test complex expressions with variables and parentheses
     */
    public function test_complex_expression()
    {
        $input = '$x = 5; $y = 10; echo $x * ($y + 2);';
        $output = $this->executeCode($input);

        $this->assertEquals("60\n", $output);
    }

    /**
     * Test all arithmetic operations with variables
     */
    public function test_arithmetic_operations()
    {
        $input = '$x = 10; $y = 2; echo $x + $y; echo $x - $y; echo $x * $y; echo $x / $y;';
        $output = $this->executeCode($input);

        $this->assertEquals("12\n8\n20\n5\n", $output);
    }

    /**
     * Test using an undefined variable (should throw an exception)
     */
    public function test_undefined_variable()
    {
        $this->expectException(\Exception::class);

        $input = 'echo $undefinedVar;';
        $interpreter = $this->createInterpreter($input);
        $interpreter->interpret();
    }

    /**
     * Test code generation with variables
     */
    public function test_code_generation()
    {
        $input = '$x = 5; echo $x;';
        $code = $this->generateCode($input);

        // Test that code contains variable operations
        $this->assertStringContainsString('STORE', $code);
        $this->assertStringContainsString('LOAD', $code);
        $this->assertStringContainsString('PRINT', $code);
    }

    /**
     * Test complex code generation with multiple variables
     */
    public function test_complex_code_generation()
    {
        $input = '$x = 5; $y = 10; echo $x * ($y + 2);';
        $code = $this->generateCode($input);

        // Test that code contains all needed operations
        $this->assertStringContainsString('PUSH 5', $code);
        $this->assertStringContainsString('PUSH 10', $code);
        $this->assertStringContainsString('PUSH 2', $code);
        $this->assertStringContainsString('ADD', $code);
        $this->assertStringContainsString('MUL', $code);
        $this->assertStringContainsString('STORE', $code);
        $this->assertStringContainsString('LOAD', $code);
        $this->assertStringContainsString('PRINT', $code);
    }
}
