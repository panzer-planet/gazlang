<?php

namespace GazLang\Tests;

/**
 * lib/db.gaz and the PostgreSQL driver, against a real server: tests/db/pg_check.gaz, which uses
 * temporary tables only. Skipped without GAZLANG_TEST_PG, the server's URL
 * (postgres://user:password@localhost/postgres); the SQLite driver needs no server, and its
 * tests are tests/gaz/lib/db_test.gaz.
 */
class DbPgTest extends GazLangTestCase
{
    public function test_check_program_passes_against_a_server()
    {
        $url = getenv('GAZLANG_TEST_PG');
        if ($url === false || $url === '') {
            $this->markTestSkipped('GAZLANG_TEST_PG is not set');
        }

        $output = self::succeed(['-f', 'tests/db/pg_check.gaz', '--', $url]);

        $this->assertStringNotContainsString('FAIL', $output);
        $this->assertGreaterThan(20, substr_count($output, 'ok '));
    }
}
