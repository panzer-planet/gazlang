<?php

namespace GazLang\Tests;

/**
 * `gaz test [path] [--update]` (vm/vm.c's dispatch to lib/test.gaz's test::main()): it finds
 * every *_test.gaz file under path, recursively, runs each in its own gaz process (reinvoked
 * with program_path()), and reports a file as failed if it exits non-zero or its stdout has a
 * FAIL line. Fixtures are tests/fixtures/gaz_test; a testing tool needs a test that doesn't just
 * trust its own output.
 */
class TestCommandTest extends GazLangTestCase
{
    private const FIXTURES = 'tests/fixtures/gaz_test';

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function gazTest(array $args): array
    {
        return self::gazlang(['test', ...$args]);
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

    /**
     * The bootstrap program is handed to the front end as source; standard input is the user's and
     * was never read, so reading on after the source (which a program piped in whole has drained
     * already) would wait for an end that a terminal, or a pipe held open, never gives.
     */
    public function test_it_never_waits_on_standard_input_left_open()
    {
        $process = proc_open([self::binary(), 'test', self::FIXTURES.'/passing'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, self::ROOT);
        $this->assertIsResource($process);
        $deadline = microtime(true) + 20;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(50000);
        }
        $running = proc_get_status($process)['running'];
        if ($running) {
            proc_terminate($process);
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        proc_close($process);

        $this->assertFalse($running, 'gaz test waited on standard input');
        $this->assertStringContainsString('2 files run, 0 failed', $out);
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

    /**
     * gaz test reinvokes itself with program_path(), argv[0] exactly as gaz was started, so it
     * works whichever way that was, not only when found on PATH: started here as a relative
     * path from a working directory other than the project root, it must still find and run
     * itself the same way for every file it discovered.
     */
    public function test_it_reinvokes_itself_correctly_when_started_by_a_relative_path()
    {
        self::binary();
        // A shell cd, as a person typing ./bin/gaz from another directory would, rather than
        // proc_open() (whose posix_spawn() resolves a relative command before the child's chdir)
        $command = sprintf(
            'cd %s && ../bin/gaz test %s 2>&1',
            escapeshellarg(self::ROOT.'/tests'),
            escapeshellarg('../'.self::FIXTURES.'/passing')
        );
        exec($command, $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertStringContainsString('2 files run, 0 failed', implode("\n", $output));
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
