<?php

namespace GazLang\AST;

/**
 * Lambda represents an anonymous function, $x -> $x * 2 or ($a, $b = 1) -> { return $a + $b; }
 *
 * Creating one copies the outer variables it captures into the closure, which keeps them
 * between calls: they are the closure's own variables, shared by all its calls.
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
     * @var string[] The $ variables the body and defaults use that aren't parameters and that no plain
     *               =, foreach or catch in them assigns, in source order; the ones that exist when the
     *               lambda is evaluated are copied into the closure
     */
    public $captures;

    /**
     * @var array<string, int> The captured names as keys, each with its position in
     */
    public $capture_names;

    /**
     * @var string|null The variable this lambda is assigned to with = <lambda>, when it uses it:
     *                  that captured variable holds the closure itself
     */
    public $self = null;

    /**
     * Constructor
     *
     * @param  string[]  $params  The parameter names, including the $ prefix
     * @param  array<int, AST|null>  $defaults  Each parameter's default value expression, or null if required
     * @param  int|array{0: int, 1: int}  $arity  How many arguments a call takes
     * @param  AST  $body  The block or expression body
     * @param  string[]  $captures  The outer variables the lambda captures
     */
    public function __construct(array $params, array $defaults, int|array $arity, AST $body, array $captures)
    {
        $this->params = $params;
        $this->defaults = $defaults;
        $this->arity = $arity;
        $this->body = $body;
        $this->captures = $captures;
        $this->capture_names = array_flip($captures);
    }

    /**
     * Whether the body is a block, which returns explicitly, rather than an expression whose value is returned
     */
    public function isBlock(): bool
    {
        return $this->body instanceof CompoundAST;
    }
}
