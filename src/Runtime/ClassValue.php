<?php

namespace GazLang\Runtime;

use GazLang\AST\AST;
use GazLang\AST\ClassDeclarationAST;
use GazLang\AST\FunctionDeclarationAST;

/**
 * A class as a value: Point in $make = Point, and what an object's fields and methods are
 *
 * Built by each backend from the parser's resolved declarations, one value per class per
 * run, so == on classes is identity.
 */
final class ClassValue
{
    /**
     * @var string The class name
     */
    public $name;

    /**
     * @var ClassDeclarationAST The declaration
     */
    public $declaration;

    /**
     * @var array<string, true> Every field an object of this class has, the parent's first
     */
    public $fields;

    /**
     * @var array<string, self> Every method an object of this class can call (the constructor _ too), mapped to the class whose version runs
     */
    public $methods = [];

    /**
     * @var list<array{0: string, 1: AST}> The field defaults a new object gets, the parent's first, as [field, default]
     */
    public $defaults = [];

    /**
     * @var int|array{0: int, 1: int} How many arguments constructing takes: the constructor's arity, or 0 without one
     */
    public $arity;

    /**
     * Constructor
     *
     * @param  ClassDeclarationAST  $declaration  The resolved declaration
     */
    private function __construct(ClassDeclarationAST $declaration)
    {
        $this->name = $declaration->name;
        $this->declaration = $declaration;
        $this->fields = array_fill_keys(array_keys($declaration->layout), true);
    }

    /**
     * Make the values for a program's classes
     *
     * @param  iterable<ClassDeclarationAST>  $declarations  Every class, resolved by the parser
     * @return array<string, self> The classes by name
     */
    public static function build(iterable $declarations): array
    {
        $classes = [];
        foreach ($declarations as $declaration) {
            $classes[$declaration->name] = new self($declaration);
        }
        foreach ($classes as $class) {
            foreach ($class->declaration->members as $method => $definer) {
                $class->methods[$method] = $classes[$definer];
            }
            foreach ($class->declaration->layout as $field => $declarer) {
                $default = $classes[$declarer]->declaration->fields[$field];
                if ($default !== null) {
                    $class->defaults[] = [$field, $default];
                }
            }
            $class->arity = isset($class->methods['_']) ? $class->methods['_']->method('_')->arity : 0;
        }

        return $classes;
    }

    /**
     * The declaration of a method this class declares itself
     *
     * @param  string  $name  The method name
     */
    public function method(string $name): FunctionDeclarationAST
    {
        return $this->declaration->methods[$name];
    }

    /**
     * Whether the class can't be constructed
     */
    public function isAbstract(): bool
    {
        return $this->declaration->abstract;
    }
}
