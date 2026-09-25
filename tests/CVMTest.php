<?php

namespace GazLang\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The C VM, under the sanitizers, against what each entry in vm/passing.txt must print: the
 * standard output, standard error and exit code recorded in tests/expected (see CVM)
 *
 * vm/progress.php finds new entries, adds them and records what they print.
 */
class CVMTest extends TestCase
{
    /**
     * @var array<string, mixed> Each entry's C result, run all at once so they run in parallel
     */
    private static $results = [];

    public static function setUpBeforeClass(): void
    {
        CVM::build();
        self::$results = CVM::runC(array_keys(self::entries()));
    }

    public static function entries(): array
    {
        $entries = [];
        foreach (array_diff(CVM::passing(), CVM::gone()) as $entry) {
            $entries[$entry] = [$entry];
        }

        return $entries;
    }

    /**
     * @dataProvider entries
     */
    public function test_the_c_vm_prints_what_is_expected(string $entry)
    {
        $expected = CVM::expected($entry);
        $this->assertNotNull($expected, "{$entry}: nothing recorded in tests/expected; php vm/progress.php --update");
        $c = self::$results[$entry] ??= CVM::runC([$entry])[$entry];

        $this->assertSame($expected[1], CVM::portable($c[1]), "{$entry}: standard error");
        $this->assertSame($expected[0], CVM::portable($c[0]), "{$entry}: standard output");
        $this->assertSame($expected[2], $c[2], "{$entry}: exit code");
        // A missing decref changes no output, so the C VM counts what it leaves alive
        $this->assertNull(CVM::leak($c), "{$entry}: the C VM leaked");
        if (str_starts_with($entry, 'tests/bytecode_corpus/')) {
            $this->assertSame(str_starts_with(basename($entry), 'error_'), $expected[2] !== 0, "{$entry}: only files named error_* are refused");
        }
    }

    public function test_every_entry_still_has_its_file_or_snippet()
    {
        $this->assertSame([], CVM::gone(), 'in vm/passing.txt, but gone: php vm/progress.php --update removes them');
    }

    public function test_everything_in_tests_expected_belongs_to_an_entry()
    {
        $wanted = array_map(fn ($entry) => CVM::expectedPath($entry), array_keys(self::entries()));
        $stale = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(CVM::ROOT.'/tests/expected', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $path) {
            $base = preg_replace('/\.(stdout|stderr|exit)$/', '', (string) $path);
            if (! in_array($base, $wanted, true)) {
                $stale[] = substr((string) $path, strlen(CVM::ROOT) + 1);
            }
        }
        $this->assertSame([], $stale, 'no entry in vm/passing.txt records these: php vm/progress.php --update removes them');
    }

    public function test_the_self_hosted_compiler_compiles_itself_to_itself()
    {
        // The compiler built into the VM (compiler/gazlang.gzb), under the sanitizers, compiles
        // its own source to exactly itself: anything else means the source changed without
        // make compiler, or a compiler whose output depends on how it was compiled
        [$out, $err, $code] = CVM::process([CVM::BINARY, '-f', 'compiler/gazlang.gzb', '--', 'code', CVM::DRIVER]);
        $this->assertSame([0, ''], [$code, $err]);
        $this->assertSame(file_get_contents(CVM::ROOT.'/compiler/gazlang.gzb'), $out, 'compiler/gazlang.gzb is stale: make -C vm compiler');
    }

    public function test_make_compiler_rebuilds_the_compiler_without_php()
    {
        // On a copy of what the target reads, the VM included, with its times kept so that make
        // doesn't build it again first
        $dir = sys_get_temp_dir().'/gazlang_bootstrap_'.getmypid();
        $files = ['VERSION', 'compiler/*.gaz', 'compiler/gazlang.gzb', 'lib/*.gaz', 'vm/Makefile', 'vm/*.[ch]', 'bin/gaz', 'vm/build/compiler.c', 'vm/build/std.c', 'vm/build/config', 'vm/build/pgo-mode'];
        exec('mkdir -p '.escapeshellarg($dir).' && cd '.escapeshellarg(CVM::ROOT).' && tar cf - '.implode(' ', $files).' | tar xf - -C '.escapeshellarg($dir).' 2>&1', $output, $code);
        try {
            $this->assertSame(0, $code, implode("\n", $output));
            $make = fn () => CVM::process(['make', '-s', '-C', "{$dir}/vm", 'compiler']);
            $compiler = "{$dir}/compiler/gazlang.gzb";
            $before = [file_get_contents($compiler), filemtime("{$dir}/bin/gaz")];

            // An edit that breaks the compiler is refused, and changes nothing
            file_put_contents("{$dir}/compiler/parser.gaz", "fn (\n", FILE_APPEND);
            [, $err, $code] = $make();
            $this->assertNotSame(0, $code);
            $this->assertStringContainsString("Expected a name but found '('", $err);
            $this->assertSame($before, [file_get_contents($compiler), filemtime("{$dir}/bin/gaz")]);
            copy(CVM::ROOT.'/compiler/parser.gaz', "{$dir}/compiler/parser.gaz");

            // So is a code generator that miscompiles the string "LOAD": the old compiler compiles
            // it correctly (stage 1), so it compiles itself wrongly (stage 2), which then writes
            // LOAD instructions wrongly (stage 3)
            $codegen = file_get_contents(CVM::ROOT.'/compiler/codegen.gaz');
            $push = 'StringAST => #emit("PUSH", [$node.value]),';
            $this->assertStringContainsString($push, $codegen);
            file_put_contents("{$dir}/compiler/codegen.gaz", str_replace($push, 'StringAST => #emit("PUSH", [$node.value == "LOAD" ? "LOAD " : $node.value]),', $codegen));
            [, $err, $code] = $make();
            $this->assertNotSame(0, $code);
            $this->assertStringContainsString('stage 2 differs from stage 3', $err);
            $this->assertSame($before, [file_get_contents($compiler), filemtime("{$dir}/bin/gaz")]);
            copy(CVM::ROOT.'/compiler/codegen.gaz', "{$dir}/compiler/codegen.gaz");

            // One that moves every location after it is taken: the compiler is rebuilt, compiles
            // itself to itself, and the VM is rebuilt with it
            file_put_contents("{$dir}/compiler/lexer.gaz", "// a line\n".file_get_contents(CVM::ROOT.'/compiler/lexer.gaz'));
            [, $err, $code] = $make();
            $this->assertSame([0, ''], [$code, $err]);
            $rebuilt = file_get_contents($compiler);
            $this->assertNotSame($before[0], $rebuilt);
            // (from vm/, as the Makefile compiles it, since the paths it writes depend on that)
            $this->assertSame([$rebuilt, '', 0], CVM::processes([["{$dir}/bin/gaz", '-f', '../compiler/gazlang.gzb', '--', 'code', '../compiler/gazlang.gaz']], cwd: "{$dir}/vm")[0]);
            // The VM holds the compiler's bytes as they are, in a C array
            $this->assertStringContainsString($rebuilt, file_get_contents("{$dir}/bin/gaz"), 'bin/gaz was not rebuilt');
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
