<?php

namespace GazLang\Runtime;

/**
 * A function as a value: what a bare function name evaluates to ($f = add;)
 *
 * Only the name is held; each backend resolves it when the value is called. Named
 * functions are interned, one instance per name, so add == add and in_array find
 * them by identity. Anonymous functions (later) will be fresh per creation and compare
 * by identity of creation, like PHP closures, so nothing should depend on named().
 */
final class FunctionValue
{
    /**
     * @var array<string, self> The interned value for each function name
     */
    private static $named = [];

    /**
     * @var string The function's name, a user function or a builtin
     */
    public $name;

    /**
     * Constructor
     *
     * @param  string  $name  The function's name
     */
    private function __construct(string $name)
    {
        $this->name = $name;
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
}
