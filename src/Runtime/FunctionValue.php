<?php

namespace GazLang\Runtime;

use GazLang\AST\LambdaAST;
use GazLang\GazLangError;

/**
 * A function as a value: a bare function name ($f = add;) or an anonymous function ($x -> $x * 2)
 *
 * A named function holds only its name; each backend resolves it when the value is called.
 * Named values are interned, one instance per name, so add == add and in_array find them
 * by identity. A closure holds its LambdaAST and the outer variables it captured, copied
 * when it was created and then its own, kept between calls (the interpreter keys them by
 * name, the VM by index in LambdaAST::$captures);
 * every evaluation of a lambda makes a fresh value, so two closures are == only when they
 * are the same one, like PHP closures.
 */
final class FunctionValue
{
    /**
     * @var array<string, self> The interned value for each function name
     */
    private static $named = [];

    /**
     * @var string|null The function's name, a user function or a builtin; null for a closure
     */
    public $name;

    /**
     * @var LambdaAST|null The lambda a closure was made from
     */
    public $lambda;

    /**
     * @var array The captured variables of a closure, by name (interpreter) or capture index (VM), changed by its calls
     */
    public $captured;

    /**
     * @var int|null The VM's index for the closure's lambda (its entry and capture map), unused by the interpreter
     */
    public $index;

    /**
     * Constructor
     *
     * @param  string|null  $name  The function's name, or null for a closure
     * @param  LambdaAST|null  $lambda  The lambda a closure was made from
     * @param  array  $captured  A closure's captured variables
     * @param  int|null  $index  The VM's lambda index
     */
    private function __construct(?string $name, ?LambdaAST $lambda = null, array $captured = [], ?int $index = null)
    {
        $this->name = $name;
        $this->lambda = $lambda;
        $this->captured = $captured;
        $this->index = $index;
    }

    /**
     * The value for a named function, the same instance every time
     *
     * @param  string  $name  The function's name
     */
    public static function named(string $name): self
    {
        return self::$named[$name] ??= new self($name);
    }

    /**
     * A new closure over a lambda
     *
     * @param  LambdaAST  $lambda  The lambda
     * @param  array  $captured  The captured variables, keyed as the backend needs
     * @param  int|null  $index  The VM's index for the lambda
     */
    public static function closure(LambdaAST $lambda, array $captured, ?int $index = null): self
    {
        return new self(null, $lambda, $captured, $index);
    }

    /**
     * How the function is named in output and messages: "add", or "-> at file.gaz:12" / "-> on line 12" for a closure
     */
    public function describe(): string
    {
        return $this->name ?? '-> '.GazLangError::location($this->lambda->file, $this->lambda->line);
    }
}
