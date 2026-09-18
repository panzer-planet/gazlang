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
        $stages = [CVM::compile(CVM::DRIVER)];
        foreach ([1, 2] as $stage) {
            [$out, $err, $code] = CVM::process([CVM::BINARY, '-f', $stages[$stage - 1], '--', 'code', CVM::DRIVER]);
            $this->assertSame([0, ''], [$code, $err], "stage {$stage} failed");
            $stages[$stage] = "vm/build/gzb/stage{$stage}.gzb";
            file_put_contents(CVM::ROOT.'/'.$stages[$stage], $out);
        }
        $bytecode = array_map(fn ($gzb) => file_get_contents(CVM::ROOT.'/'.$gzb), $stages);
        $this->assertSame($bytecode[0], $bytecode[1], 'stage 1 differs from the PHP compiler\'s');
        $this->assertSame($bytecode[1], $bytecode[2], 'stage 2 differs from stage 1');
        // The compiler built into the C VM, which must be this same bytecode
        $this->assertSame($bytecode[0], file_get_contents(CVM::ROOT.'/selfhost/gazlang.gzb'), 'selfhost/gazlang.gzb is stale: make -C vm compiler');
    }

    public function test_make_compiler_rebuilds_the_compiler_without_php()
    {
        // On a copy of what the target reads, the VM included, with its times kept so that make
        // doesn't build it again first
        $dir = sys_get_temp_dir().'/gazlang_bootstrap_'.getmypid();
        $files = ['selfhost/*.gaz', 'selfhost/gazlang.gzb', 'lib/chars.gaz', 'vm/Makefile', 'vm/*.[ch]', 'bin/gazlang', 'vm/build/compiler.c'];
        exec('mkdir -p '.escapeshellarg($dir).' && cd '.escapeshellarg(CVM::ROOT).' && tar cf - '.implode(' ', $files).' | tar xf - -C '.escapeshellarg($dir).' 2>&1', $output, $code);
        try {
            $this->assertSame(0, $code, implode("\n", $output));
            $make = fn () => CVM::process(['make', '-s', '-C', "{$dir}/vm", 'compiler']);
            $compiler = "{$dir}/selfhost/gazlang.gzb";
            $before = [file_get_contents($compiler), filemtime("{$dir}/bin/gazlang")];

            // An edit that breaks the compiler is refused, and changes nothing
            file_put_contents("{$dir}/selfhost/parser.gaz", "fn (\n", FILE_APPEND);
            [, $err, $code] = $make();
            $this->assertNotSame(0, $code);
            $this->assertStringContainsString("Expected a name but found '('", $err);
            $this->assertSame($before, [file_get_contents($compiler), filemtime("{$dir}/bin/gazlang")]);
            copy(CVM::ROOT.'/selfhost/parser.gaz', "{$dir}/selfhost/parser.gaz");

            // So is a code generator that miscompiles the string "LOAD": the old compiler compiles
            // it correctly (stage 1), so it compiles itself wrongly (stage 2), which then writes
            // LOAD instructions wrongly (stage 3)
            $codegen = file_get_contents(CVM::ROOT.'/selfhost/codegen.gaz');
            $push = 'StringAST => #emit("PUSH", [$node.value]),';
            $this->assertStringContainsString($push, $codegen);
            file_put_contents("{$dir}/selfhost/codegen.gaz", str_replace($push, 'StringAST => #emit("PUSH", [$node.value == "LOAD" ? "LOAD " : $node.value]),', $codegen));
            [, $err, $code] = $make();
            $this->assertNotSame(0, $code);
            $this->assertStringContainsString('stage 2 differs from stage 3', $err);
            $this->assertSame($before, [file_get_contents($compiler), filemtime("{$dir}/bin/gazlang")]);
            copy(CVM::ROOT.'/selfhost/codegen.gaz', "{$dir}/selfhost/codegen.gaz");

            // One that moves every location after it is taken: the compiler is what the PHP
            // compiler writes, and the VM is rebuilt with it
            file_put_contents("{$dir}/selfhost/lexer.gaz", "// a line\n".file_get_contents(CVM::ROOT.'/selfhost/lexer.gaz'));
            // (with the PHP compiler compiling the same, from the same directory, meanwhile)
            [$made, $php] = CVM::processes([['make', '-s', 'compiler'], ['php', CVM::ROOT.'/bin/gazlang-php', '-c', '-f', '../selfhost/gazlang.gaz']], cwd: "{$dir}/vm");
            $this->assertSame([0, ''], [$made[2], $made[1]]);
            $this->assertNotSame($before[0], file_get_contents($compiler));
            $this->assertSame($php[0], file_get_contents($compiler));
            // The VM holds the compiler's bytes as they are, in a C array
            $this->assertStringContainsString($php[0], file_get_contents("{$dir}/bin/gazlang"), 'bin/gazlang was not rebuilt');
        } finally {
            exec('rm -rf '.escapeshellarg($dir));
        }
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
