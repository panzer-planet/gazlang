<?php

namespace GazLang\Tests;

class ClassTest extends GazLangTestCase
{
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
            'dot after #' => ['echo #.name;', 'Write #name, not #.name on line 1'],
            'bare ##' => ['echo ##;', "## alone is not allowed: write ##name for the parent's version of a method on line 1"],
        ];
    }
}
