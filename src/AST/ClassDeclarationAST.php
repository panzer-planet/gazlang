<?php

namespace GazLang\AST;

/**
 * ClassDeclaration represents a top level class in the AST
 */
class ClassDeclarationAST extends AST
{
    /**
     * @var string The class name
     */
    public $name;

    /**
     * @var string|null The parent class's name, or null
     */
    public $parent;

    /**
     * @var bool Whether the class is abstract, so it can't be constructed
     */
    public $abstract;

    /**
     * @var array<string, AST|null> The fields this class declares, by name without the #, in order, each with its default or null
     */
    public $fields = [];

    /**
     * @var array<string, int> The line each field is declared on
     */
    public $field_lines = [];

    /**
     * @var array<string, FunctionDeclarationAST> The methods this class declares, abstract ones included, by name
     */
    public $methods = [];

    /**
     * @var array<string, AST> The expression of each constant the class declares, by name
     */
    public $constants = [];

    /**
     * @var array<string, int> The line each constant is declared on, for errors about it
     */
    public $constant_lines = [];

    /**
     * @var array<string, string> Every constant, the parent's first, with the class that declares it;
     *                            worked out once the whole program is read
     */
    public $constant_owners = [];

    /**
     * @var array<string, string> Set by the parser once the whole program is read: every field an object of this
     *                            class has (the parent's first), mapped to the class that declares it
     */
    public $layout = [];

    /**
     * @var array<string, string> Set by the parser: every method an object of this class can call, the constructor
     *                            _ included and abstract ones not, mapped to the class whose version runs
     */
    public $members = [];

    /**
     * @var array<string, string> Set by the parser: every abstract method no class on the way defines, mapped to the class declaring it
     */
    public $abstract_methods = [];

    /**
     * @var bool Whether the parser has worked out the layout and members
     */
    public $resolved = false;

    /**
     * Constructor
     *
     * @param  string  $name  The class name
     * @param  string|null  $parent  The parent class's name, or null
     * @param  bool  $abstract  Whether the class is abstract
     */
    public function __construct(string $name, ?string $parent, bool $abstract)
    {
        $this->name = $name;
        $this->parent = $parent;
        $this->abstract = $abstract;
    }

    /**
     * This class as a record the backends run from: no AST, since the field defaults are code
     *
     * @return array{parent: string|null, abstract: bool, fields: array<string, string>, methods: array<string, string>}
     */
    public function record(): array
    {
        return ['parent' => $this->parent, 'abstract' => $this->abstract, 'fields' => $this->layout, 'methods' => $this->members];
    }
}
