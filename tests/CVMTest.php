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
        // A missing decref changes no output, so the C VM counts what it leaves alive
        $this->assertNull(CVM::leak($c), "{$entry}: the C VM leaked");
        if (str_starts_with($entry, 'tests/bytecode_corpus/')) {
            $this->assertSame(str_starts_with(basename($entry), 'error_'), $php[2] !== 0, "{$entry}: only files named error_* are refused");
        }
    }

    public function test_the_self_hosted_compiler_compiles_itself_on_the_c_vm()
    {
        // The bootstrap: the PHP compiler's bytecode for the self-hosted compiler (stage 0), run
        // on the C VM, compiles the compiler (stage 1), which compiles it again (stage 2). All
        // three must be the same bytecode, byte for byte, or the compiler can't replace PHP
        $stages = [CVM::compile('selfhost/compile.gaz')];
        foreach ([1, 2] as $stage) {
            [$out, $err, $code] = CVM::process([CVM::BINARY, $stages[$stage - 1], 'selfhost/compile.gaz']);
            $this->assertSame([0, ''], [$code, $err], "stage {$stage} failed");
            $stages[$stage] = "vm/build/gzb/stage{$stage}.gzb";
            file_put_contents(CVM::ROOT.'/'.$stages[$stage], $out);
        }
        $bytecode = array_map(fn ($gzb) => file_get_contents(CVM::ROOT.'/'.$gzb), $stages);
        $this->assertSame($bytecode[0], $bytecode[1], 'stage 1 differs from the PHP compiler\'s');
        $this->assertSame($bytecode[1], $bytecode[2], 'stage 2 differs from stage 1');
        // The compiler built into the C VM, which must be this same bytecode
        $this->assertSame($bytecode[0], file_get_contents(CVM::ROOT.'/selfhost/compile.gzb'), 'selfhost/compile.gzb is stale: php bin/gazlang -c -f selfhost/compile.gaz > selfhost/compile.gzb');
    }

    public function test_the_c_vm_collects_cycles()
    {
        // 100000 iterations each make five cycles of different kinds and keep none of them:
        // nothing is left once the top level's variables are dropped, and never more than a
        // collection's worth were alive at once (the tested build collects every 64 new ones)
        [$leaked, $most] = CVM::alive('tests/vm_corpus/cycles.gaz');
        $this->assertSame(0, $leaked);
        $this->assertLessThan(1000, $most);
    }
}
