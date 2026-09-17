<?php

namespace GazLang\Runtime;

use GazLang\AST\LambdaAST;
use GazLang\GazLangError;

/**
 * A function as a value: a bare function name ($f = add;), an anonymous function ($x -> $x * 2),
 * or a method bound to an object ($doc.save, #save)
 *
 * A named function holds only its name; each backend resolves it when the value is called.
 * Named values are interned, one instance per name, so add == add and in_array find them
 * by identity. A closure holds its LambdaAST and the outer variables it captured, copied
 * when it was created and then its own, kept between calls (the interpreter keys them by
 * name, the VM by index in LambdaAST::$captures);
 * every evaluation of a lambda makes a fresh value, so two closures are == only when they
 * are the same one, like PHP closures. A closure made inside a method keeps that method's
 * object as its receiver. A bound method holds the object, the method name and the class
 * whose version runs; two are == when all three are the same.
 */
final class FunctionValue
{
    /**
     * @var array<string, self> The interned value for each function name
     */
    private static $named = [];

    /**
     * @var string|null The function's name, a user function or a builtin, or a bound method's name; null for a closure
     */
    public $name;

    /**
     * @var ClassValue|null For a bound method, the class whose version of the method runs
     */
    public $class;

    /**
     * @var ObjectValue|null The object # is in the body: a bound method's, or that of the method a closure was made in
     */
    public $receiver;

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
     * @param  ObjectValue|null  $receiver  The object # is in the body
     * @param  ClassValue|null  $class  A bound method's class
     */
    private function __construct(?string $name, ?LambdaAST $lambda = null, array $captured = [], ?int $index = null, ?ObjectValue $receiver = null, ?ClassValue $class = null)
    {
        $this->name = $name;
        $this->lambda = $lambda;
        $this->captured = $captured;
        $this->index = $index;
        $this->receiver = $receiver;
        $this->class = $class;
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
     * @param  ObjectValue|null  $receiver  The object of the method the closure is made in, if any
     */
    public static function closure(LambdaAST $lambda, array $captured, ?int $index = null, ?ObjectValue $receiver = null): self
    {
        return new self(null, $lambda, $captured, $index, $receiver);
    }

    /**
     * A method bound to an object
     *
     * @param  ObjectValue  $receiver  The object
     * @param  ClassValue  $class  The class whose version of the method runs
     * @param  string  $name  The method name
     */
    public static function bound(ObjectValue $receiver, ClassValue $class, string $name): self
    {
        return new self($name, null, [], null, $receiver, $class);
    }

    /**
     * How the function is named in output and messages: "add", "Point.area" for a bound method,
     * or "-> at file.gaz:12" / "-> on line 12" for a closure
     */
    public function describe(): string
    {
        if ($this->class !== null) {
            return "{$this->class->name}.{$this->name}";
        }

        return $this->name ?? '-> '.GazLangError::location($this->lambda->file, $this->lambda->line);
    }

    /**
     * How a call with the wrong number of arguments names it: "Function add" or "Method Point.area"
     */
    public function title(): string
    {
        return ($this->class !== null ? 'Method ' : 'Function ').$this->describe();
    }
}
