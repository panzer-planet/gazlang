<?php

namespace GazLang\AST;

/**
 * Lambda represents an anonymous function, $x -> $x * 2 or ($a, $b = 1) -> { return $a + $b; }
 *
 * Creating one copies the outer variables it uses (free) into the closure, by value.
 */
class LambdaAST extends AST
{
    /**
     * @var string[] The parameter names, including the $ prefix
     */
    public $params;

    /**
     * @var array<int, AST|null> Each parameter's default value expression, by position, or null if it is required
     */
    public $defaults;

    /**
     * @var int|array{0: int, 1: int} How many arguments a call takes: a count, or [fewest, most] with defaults
     */
    public $arity;

    /**
     * @var AST The body: a CompoundAST block (whose value is what return gives, else null) or an expression
     */
    public $body;

    /**
     * @var string[] The $ variables the body and defaults use that aren't parameters, in source order;
     *               the ones that exist when the lambda is evaluated are captured by value
     */
    public $free;

    /**
     * Constructor
     *
     * @param  string[]  $params  The parameter names, including the $ prefix
     * @param  array<int, AST|null>  $defaults  Each parameter's default value expression, or null if required
     * @param  int|array{0: int, 1: int}  $arity  How many arguments a call takes
     * @param  AST  $body  The block or expression body
     * @param  string[]  $free  The outer variables the lambda uses
     */
    public function __construct(array $params, array $defaults, int|array $arity, AST $body, array $free)
    {
        $this->params = $params;
        $this->defaults = $defaults;
        $this->arity = $arity;
        $this->body = $body;
        $this->free = $free;
    }

    /**
     * Whether the body is a block, which returns explicitly, rather than an expression whose value is returned
     */
    public function isBlock(): bool
    {
        return $this->body instanceof CompoundAST;
    }
}
