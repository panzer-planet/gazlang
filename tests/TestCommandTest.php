<?php

namespace GazLang\Tests;

/**
 * `gaz test [path] [--update]` (vm/vm.c's dispatch to lib/test.gaz's test::main()): it finds
 * every *_test.gaz file under path, recursively, runs each as its own gaz process, and reports
 * a file as failed if it exits non-zero or its stdout has a FAIL line. Fixtures are
 * tests/fixtures/gaz_test; a testing tool needs a test that doesn't just trust its own output.
 */
class TestCommandTest extends GazLangTestCase
{
    private const FIXTURES = 'tests/fixtures/gaz_test';

    /**
     * gaz test spawns "gaz" by name (run()'s posix_spawnp searches PATH; there is no builtin
     * for a running program's own executable path), so the subprocess needs bin/ on its PATH.
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private function gazTest(array $args): array
    {
        return self::gazlang(['test', ...$args], '', ['PATH' => dirname(self::binary()).':'.getenv('PATH')]);
    }

    public function test_it_runs_every_test_file_recursively_and_reports_a_failure()
    {
        [$out, $err, $code] = $this->gazTest([self::FIXTURES]);

        $this->assertSame(1, $code, $out.$err);
        $this->assertStringContainsString('ok addition', $out);
        $this->assertStringContainsString('ok multiplication', $out);
        $this->assertStringContainsString('ok the_value', $out);
        $this->assertStringContainsString('FAIL subtraction: expected 2 (int), got 3 (int)', $out);
        $this->assertStringContainsString('3 files run, 1 failed', $out);
    }

    public function test_a_directory_scoped_to_only_passing_files_exits_zero()
    {
        [$out, $err, $code] = $this->gazTest([self::FIXTURES.'/passing']);

        $this->assertSame(0, $code, $out.$err);
        $this->assertStringContainsString('2 files run, 0 failed', $out);
    }

    public function test_a_single_file_can_be_named_directly()
    {
        [$out, , $code] = $this->gazTest([self::FIXTURES.'/passing/pass_test.gaz']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('1 file run, 0 failed', $out);
    }

    public function test_a_missing_path_is_an_error()
    {
        [, $err, $code] = $this->gazTest([self::FIXTURES.'/nowhere']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('does not exist', $err);
    }

    public function test_update_records_a_snapshot_and_a_later_run_compares_against_it()
    {
        $dir = sys_get_temp_dir().'/gaz_test_snapshot_'.uniqid();
        mkdir($dir);
        $file = $dir.'/snapshot_test.gaz';
        $snapshot = $dir.'/snapshot_test.the_value.snap';
        try {
            copy(self::ROOT.'/'.self::FIXTURES.'/passing/snapshot_test.gaz', $file);
            $this->assertFileDoesNotExist($snapshot);

            [$out, $err, $code] = $this->gazTest([$dir, '--update']);
            $this->assertSame(0, $code, $out.$err);
            $this->assertStringContainsString('snapshot recorded', $out);
            $this->assertFileExists($snapshot);
            $this->assertSame('["a snapshot", 1, 2.5]', file_get_contents($snapshot));

            // A second run compares against what --update just recorded: still green
            [$out, $err, $code] = $this->gazTest([$dir]);
            $this->assertSame(0, $code, $out.$err);

            // A stale snapshot fails the comparison, naming what changed
            file_put_contents($snapshot, 'something else');
            [$out, $err, $code] = $this->gazTest([$dir]);
            $this->assertSame(1, $code, $out.$err);
            $this->assertStringContainsString('FAIL the_value: snapshot', $out);
        } finally {
            @unlink($snapshot);
            @unlink($file);
            @rmdir($dir);
        }
    }
}
