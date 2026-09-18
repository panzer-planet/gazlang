<?php

namespace GazLang\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The C VM against the PHP VM: every entry in vm/passing.txt must print the same standard
 * output and standard error, and exit with the same code, on both (see CVM)
 *
 * The list only grows: vm/progress.php finds the entries that newly pass and adds them.
 */
class CVMTest extends TestCase
{
    /**
     * @var array<string, mixed> Each entry's results, run all at once so the C VM runs in parallel
     */
    private static $results = [];

    public static function setUpBeforeClass(): void
    {
        CVM::build();
        self::$results = CVM::runAll(array_keys(self::entries()));
    }

    /**
     * The entries without arguments; the ones with arguments are the self-hosted drivers on big
     * inputs, seconds each on the PHP VM, which the whole-repository group runs
     */
    public static function entries(): array
    {
        return self::select(false);
    }

    public static function drivers(): array
    {
        return self::select(true);
    }

    private static function select(bool $with_arguments): array
    {
        $entries = [];
        foreach (CVM::passing() as $entry) {
            if (str_contains($entry, ' ') === $with_arguments) {
                $entries[$entry] = [$entry];
            }
        }

        return $entries;
    }

    /**
     * @dataProvider drivers
     *
     * @group whole-repository
     */
    public function test_the_c_vm_matches_the_php_vm_on_the_drivers(string $entry)
    {
        $this->test_the_c_vm_matches_the_php_vm($entry);
    }

    /**
     * @dataProvider entries
     */
    public function test_the_c_vm_matches_the_php_vm(string $entry)
    {
        self::$results[$entry] ??= CVM::runAll([$entry])[$entry];
        $result = self::$results[$entry] ?? null;
        $this->assertNotNull($result, "{$entry} no longer compiles");
        [$php, $c] = $result;

        $this->assertSame($php[1], $c[1], "{$entry}: standard error");
        $this->assertSame($php[0], $c[0], "{$entry}: standard output");
        $this->assertSame($php[2], $c[2], "{$entry}: exit code");
    }
}
