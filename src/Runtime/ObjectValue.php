<?php

namespace GazLang\Runtime;

/**
 * An object: a handle, so assigning or passing one shares it, and == is identity
 */
final class ObjectValue
{
    /**
     * @var ClassValue The object's class
     */
    public $class;

    /**
     * @var array<string, mixed> The fields that are set, by name; a declared field that was never set is missing
     */
    public $fields = [];

    /**
     * Constructor
     *
     * @param  ClassValue  $class  The object's class
     */
    public function __construct(ClassValue $class)
    {
        $this->class = $class;
    }
}
