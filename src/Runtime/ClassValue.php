<?php

namespace GazLang\Runtime;

/**
 * A class as a value: Point in $make = Point, and what an object's fields and methods are
 *
 * Built by the VM from the class records the parser's resolved declarations give, one value
 * per class per run, so == on classes is identity. A record holds no AST: the field defaults
 * are code (the class's initialiser), so a class is its name, its parent, whether it is abstract, its fields in layout order
 * and its method table.
 */
final class ClassValue
{
    /**
     * @var string The class name
     */
    public $name;

    /**
     * @var self|null The parent class
     */
    public $parent = null;

    /**
     * @var bool Whether the class can't be constructed
     */
    public $abstract;

    /**
     * @var array<string, string> Every field an object of this class has, the parent's first, mapped to the class that declares it
     */
    public $fields;

    /**
     * @var array<string, self> Every method an object of this class can call (the constructor _ too), mapped to the class whose version runs
     */
    public $methods = [];

    /**
     * @var array<string, array{0: int, 1: int|array{0: int, 1: int}, 2: string}> For the VM: each method an object of this class
     *                                                                            can call, the constructor aside, as [entry position, arity,
     *                                                                            "Class.name" of the version that runs], resolved when it links
     */
    public $entries = [];

    /**
     * @var int|array{0: int, 1: int} How many arguments constructing takes: the constructor's arity, or 0 without one
     */
    public $arity;

    /**
     * Constructor
     *
     * @param  string  $name  The class name
     * @param  array{parent: string|null, abstract: bool, fields: array<string, string>, methods: array<string, string>}  $record  The class record
     */
    private function __construct(string $name, array $record)
    {
        $this->name = $name;
        $this->abstract = $record['abstract'];
        $this->fields = $record['fields'];
    }

    /**
     * Make the values for a program's classes
     *
     * @param  array<string, array{parent: string|null, abstract: bool, fields: array<string, string>, methods: array<string, string>}>  $records  Every class, by name
     * @param  array<string, int|array{0: int, 1: int}>  $arities  Each method's arity, keyed "Class.name"
     * @return array<string, self> The classes by name
     */
    public static function build(array $records, array $arities): array
    {
        $classes = [];
        foreach ($records as $name => $record) {
            $classes[$name] = new self($name, $record);
        }
        foreach ($records as $name => $record) {
            $class = $classes[$name];
            $class->parent = $record['parent'] === null ? null : $classes[$record['parent']];
            foreach ($record['methods'] as $method => $definer) {
                $class->methods[$method] = $classes[$definer];
            }
            $class->arity = isset($record['methods']['_']) ? $arities["{$record['methods']['_']}._"] : 0;
        }

        return $classes;
    }

    /**
     * Whether this class is the given class or extends it, directly or not
     *
     * @param  self  $class  The class
     */
    public function isA(self $class): bool
    {
        for ($ancestor = $this; $ancestor !== null; $ancestor = $ancestor->parent) {
            if ($ancestor === $class) {
                return true;
            }
        }

        return false;
    }
}
