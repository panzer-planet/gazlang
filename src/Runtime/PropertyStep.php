<?php

namespace GazLang\Runtime;

/**
 * A property step in a write path, like .total in $rows[0].total = 5
 *
 * Index steps are the keys themselves (an int or string, or null to append), so writes
 * through lists and maps don't pay for the tag.
 */
final class PropertyStep
{
    /**
     * @var string The member name
     */
    public $name;

    /**
     * Constructor
     *
     * @param  string  $name  The member name
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
