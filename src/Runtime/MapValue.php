<?php

namespace GazLang\Runtime;

/**
 * A GazLang map: int and string keys, kept distinct ("1" is not 1), in insertion order
 *
 * Lists are plain PHP lists; a map needs its own type so {} and [] differ. Maps are values
 * like lists: the only code that changes a map in place is Values::store(), which clones
 * each map on the path first. Cloning is cheap because PHP shares the items array until
 * one side writes to it, so a map that nothing else holds is still updated in place.
 *
 * PHP turns a string key like "1" into the int 1, so keys are stored encoded (see key()):
 * ints and ordinary strings as they are, and a string PHP would convert, or one starting
 * with a NUL byte, with a NUL byte in front.
 */
final class MapValue
{
    /**
     * @var array<int|string, mixed> The elements, by encoded key
     */
    public $items;

    /**
     * Constructor
     *
     * @param  array<int|string, mixed>  $items  The elements, by encoded key
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Encode a key for $items
     *
     * @param  int|string  $key  The GazLang key
     * @return int|string The key PHP stores it under, unchanged unless PHP would convert it
     */
    public static function key(int|string $key): int|string
    {
        // Most keys are ints or names, which PHP never converts
        if (is_int($key) || $key === '' || $key[0] > '9') {
            return $key;
        }

        return $key[0] === "\0" || (string) (int) $key === $key ? "\0".$key : $key;
    }

    /**
     * Decode a key of $items back into the GazLang key
     *
     * @param  int|string  $key  The stored key
     */
    public static function unkey(int|string $key): int|string
    {
        return is_string($key) && $key !== '' && $key[0] === "\0" ? substr($key, 1) : $key;
    }

    /**
     * The GazLang keys, in order
     *
     * @return list<int|string>
     */
    public function keys(): array
    {
        return array_map(self::unkey(...), array_keys($this->items));
    }
}
