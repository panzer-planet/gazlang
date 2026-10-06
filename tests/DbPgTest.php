<?php

namespace GazLang\Tests;

/**
 * lib/db.gaz and the PostgreSQL driver, against a real server: tests/db/pg_check.gaz, which uses
 * temporary tables only, and tests/db/pg_workers.gaz, a connection opened before workers() and
 * workers opening their own on the libpq the master loaded. Skipped without GAZLANG_TEST_PG, the
 * server's URL (postgres://user:password@localhost/postgres), except workers refused a connection,
 * which needs libpq but no server; the SQLite driver needs no server, and its tests are
 * tests/gaz/lib/db_test.gaz.
 */
class DbPgTest extends GazLangTestCase
{
    /**
     * Seconds a run of pg_workers.gaz may take: a worker garbling a shared connection hangs
     */
    private const LIMIT = 20;

    public function test_check_program_passes_against_a_server()
    {
        $output = self::succeed(['-f', 'tests/db/pg_check.gaz', '--', self::url()]);

        $this->assertStringNotContainsString('FAIL', $output);
        $this->assertGreaterThan(20, substr_count($output, 'ok '));
    }

    public function test_a_worker_letting_go_of_an_inherited_connection_leaves_it_working()
    {
        [$out, $err, $code] = self::workers('drop');

        $this->assertSame([str_repeat("master's session 1\n", 5), '', 0], [$out, $err, $code]);
    }

    public function test_workers_are_refused_an_inherited_connection_rather_than_share_it()
    {
        [$out, $err, $code] = self::workers('share');

        $this->assertSame([str_repeat("worker refused 100\n", 3), '', 0], [$out, $err, $code]);
    }

    /**
     * Nothing is opened before workers(), but the program names db_open, so workers() loads libpq
     * in the master and each worker's first open uses it. A worker never loads libpq itself, by
     * design: on macOS Homebrew's libpq brings in Kerberos.framework, whose Objective-C classes
     * can't be set up in a child forked from a process with two threads, and the worker is killed.
     */
    public function test_a_worker_uses_the_libpq_its_master_loaded()
    {
        [$out, $err, $code] = self::workers('own');

        $this->assertSame([str_repeat("worker's own 1\n", 3), '', 0], [$out, $err, $code]);
    }

    /**
     * Each worker reaches a port where nothing listens and gets the catchable error, needing no
     * server. On macOS libpq's default gssencmode, "prefer", asked Kerberos for credentials on any
     * TCP connection, which set up Objective-C classes in the worker, and the runtime aborted it.
     */
    public function test_a_worker_refused_a_connection_gets_an_error_rather_than_abort()
    {
        $url = 'postgres://gaz@127.0.0.1:1/gaz';
        [$probe] = self::gazlang(['-e', "try { db_open(\"{$url}\"); } catch (Error \$e) { echo \$e.message; }"]);
        if (! str_contains($probe, 'cannot connect')) {
            self::markTestSkipped("PostgreSQL can't be tried: {$probe}");
        }

        [$out, $err, $code] = self::workers('refused', $url);

        $this->assertSame([str_repeat("worker refused\n", 3), '', 0], [$out, $err, $code]);
    }

    /**
     * The server's URL, or the test is skipped
     */
    private static function url(): string
    {
        $url = getenv('GAZLANG_TEST_PG');
        if ($url === false || $url === '') {
            self::markTestSkipped('GAZLANG_TEST_PG is not set');
        }

        return $url;
    }

    /**
     * Run tests/db/pg_workers.gaz in a mode, on GAZLANG_TEST_PG's server unless given a URL,
     * stopping it after LIMIT seconds
     *
     * @return array{0: string, 1: string, 2: int} Standard output, standard error and the exit code
     */
    private static function workers(string $mode, ?string $url = null): array
    {
        $url ??= self::url();
        // Without what would hide the macOS fork abort, so the test sees gaz's own default
        $environment = array_diff_key(getenv(), ['PGGSSENCMODE' => true, 'OBJC_DISABLE_INITIALIZE_FORK_SAFETY' => true]);
        $process = proc_open([self::binary(), 'tests/db/pg_workers.gaz', $url, $mode], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, self::ROOT, $environment);
        if ($process === false) {
            throw new \RuntimeException('Cannot run gaz');
        }
        for ($waited = 0; proc_get_status($process)['running'] && $waited < self::LIMIT * 10; $waited++) {
            usleep(100000);
        }
        $status = proc_get_status($process);
        if ($status['running']) {
            // The master stops its workers, killing what doesn't stop within its grace
            proc_terminate($process);
            proc_close($process);
            self::fail("pg_workers.gaz {$mode} still ran after ".self::LIMIT.' seconds');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);

        return [$out, $err, $status['exitcode']];
    }
}
