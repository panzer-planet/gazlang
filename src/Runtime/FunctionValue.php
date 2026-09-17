<?php

namespace GazLang\Runtime;

use GazLang\AST\LambdaAST;

/**
 * A function as a value: a bare function name ($f = add;) or an anonymous function ($x -> $x * 2)
 *
 * A named function holds only its name; each backend resolves it when the value is called.
 * Named values are interned, one instance per name, so add == add and in_array find them
 * by identity. A closure holds its LambdaAST and the outer variables it captured, copied
 * by value when it was created (the interpreter keys them by name, the VM by local slot);
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
     * @var array The captured outer variables of a closure, by name (interpreter) or local slot (VM)
     */
    public $captured;

    /**
     * @var string|null The file a closure was created in, as shown in errors
     */
    public $file;

    /**
     * @var int|null The line a closure was created on
     */
    public $line;

    /**
     * Constructor
     *
     * @param  string|null  $name  The function's name, or null for a closure
     * @param  LambdaAST|null  $lambda  The lambda a closure was made from
     * @param  array  $captured  A closure's captured variables
     * @param  string|null  $file  Where a closure was created
     * @param  int|null  $line  Where a closure was created
     */
    private function __construct(?string $name, ?LambdaAST $lambda = null, array $captured = [], ?string $file = null, ?int $line = null)
    {
        $this->name = $name;
        $this->lambda = $lambda;
        $this->captured = $captured;
        $this->file = $file;
        $this->line = $line;
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
     * @param  string|null  $file  Where it is created
     * @param  int|null  $line  Where it is created
     */
    public static function closure(LambdaAST $lambda, array $captured, ?string $file, ?int $line): self
    {
        return new self(null, $lambda, $captured, $file, $line);
    }

    /**
     * How the function is named in output and messages: "add", or "-> at file.gaz:12" / "-> on line 12" for a closure
     */
    public function describe(): string
    {
        if ($this->name !== null) {
            return $this->name;
        }

        return $this->file === null ? "-> on line {$this->line}" : "-> at {$this->file}:{$this->line}";
    }
}
