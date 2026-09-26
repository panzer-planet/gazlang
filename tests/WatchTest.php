<?php

namespace GazLang\Tests;

/**
 * gaz --watch (vm/watch.c): the program runs again when a file it is made of changes, a compile
 * error waits for the next change, and stopping the supervisor stops the program's whole group.
 * What it prints is waited for with a deadline rather than a fixed sleep, and each program is in a
 * directory of its own, named in its arguments so that `ps` can find anything it left running.
 * The refusals are rows of CliTest.
 */
class WatchTest extends GazLangTestCase
{
    /** How long anything is waited for, in seconds */
    private const DEADLINE = 15.0;

    private string $dir = '';

    /** @var resource|null */
    private $supervisor = null;

    /** How many times a file has been written, to move its time on by as many seconds */
    private int $writes = 0;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()).'/gazlang-watch-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if ($this->supervisor !== null) {
            // Stopped as a user would, so it stops the program too; killed only if that fails
            proc_terminate($this->supervisor, SIGTERM);
            $this->waitForExit();
            proc_terminate($this->supervisor, SIGKILL);
            proc_close($this->supervisor);
        }
        foreach ((array) glob($this->dir.'/*') as $file) {
            unlink((string) $file);
        }
        rmdir($this->dir);
    }

    /**
     * Write a file in the program's directory, its time moved on so that a file system with a
     * coarse clock sees the change
     */
    private function write(string $name, string $text): void
    {
        file_put_contents("{$this->dir}/{$name}", $text);
        touch("{$this->dir}/{$name}", time() + 2 * ++$this->writes);
    }

    /**
     * Start gaz --watch main.gaz in the program's directory, its directory as the argument
     */
    private function watch(): void
    {
        $this->supervisor = proc_open(
            [self::binary(), '--watch', 'main.gaz', $this->dir],
            [['file', '/dev/null', 'r'], ['file', "{$this->dir}/stdout", 'w'], ['file', "{$this->dir}/stderr", 'w']],
            $pipes,
            $this->dir,
        ) ?: null;
        $this->assertNotNull($this->supervisor);
    }

    /**
     * Wait until the supervisor or its program has printed text on a stream, `count` times in all
     */
    private function waitFor(string $stream, string $text, int $count = 1): void
    {
        $until = microtime(true) + self::DEADLINE;
        do {
            $printed = (string) @file_get_contents("{$this->dir}/{$stream}");
            if (substr_count($printed, $text) >= $count) {
                $this->addToAssertionCount(1);

                return;
            }
            usleep(20000);
        } while (microtime(true) < $until);
        $this->fail("Never printed {$text} ({$count} times) on {$stream}; it printed:\n{$printed}");
    }

    /**
     * Wait for the supervisor to end, and give how it did
     *
     * @return array<string, mixed>
     */
    private function waitForExit(): array
    {
        $until = microtime(true) + self::DEADLINE;
        while (($status = proc_get_status($this->supervisor))['running'] && microtime(true) < $until) {
            usleep(20000);
        }

        return $status;
    }

    /**
     * The processes still running with the program's directory in their command line
     *
     * @return list<string>
     */
    private function leftRunning(): array
    {
        exec('ps -A -o pid= -o command=', $lines);

        return array_values(array_filter($lines, fn ($line) => str_contains($line, $this->dir)));
    }

    public function test_a_change_to_an_included_file_runs_the_program_again()
    {
        $this->write('main.gaz', "include \"part.gaz\";\necho \"run \" .. part();\nsleep(60);\n");
        $this->write('part.gaz', "fn part() { return 1; }\n");
        $this->watch();
        $this->waitFor('stderr', "gaz: watching main.gaz and 1 file it includes\n");
        $this->waitFor('stdout', "run 1\n");

        $this->write('part.gaz', "fn part() { return 2; }\n");
        $this->waitFor('stderr', "gaz: part.gaz changed, restarting\n");
        $this->waitFor('stdout', "run 2\n");

        // A new include is watched from the run it first appears in
        $this->write('main.gaz', "include \"part.gaz\";\ninclude \"more.gaz\";\necho \"run \" .. part() .. more();\nsleep(60);\n");
        $this->write('more.gaz', "fn more() { return \"a\"; }\n");
        $this->waitFor('stderr', "gaz: watching main.gaz and 2 files it includes\n");
        $this->waitFor('stdout', "run 2a\n");
        $this->write('more.gaz', "fn more() { return \"b\"; }\n");
        $this->waitFor('stderr', "gaz: more.gaz changed, restarting\n");
        $this->waitFor('stdout', "run 2b\n");
    }

    public function test_a_compile_error_waits_for_the_fix_and_a_program_that_ends_for_the_next_change()
    {
        $this->write('main.gaz', "include \"part.gaz\";\necho \"run \" .. part();\n");
        $this->write('part.gaz', "fn part() { return 1; }\n");
        $this->watch();
        $this->waitFor('stdout', "run 1\n");
        $this->waitFor('stderr', "gaz: waiting for a change\n");

        $this->write('part.gaz', "fn part() { return (; }\n");
        $this->waitFor('stderr', "Error: Unexpected ';' at part.gaz:1\n");
        $this->waitFor('stderr', "gaz: waiting for a change\n", 2);

        $this->write('part.gaz', "fn part() { return 3; }\n");
        $this->waitFor('stdout', "run 3\n");
        $this->waitFor('stderr', "gaz: waiting for a change\n", 3);
    }

    public function test_stopping_the_supervisor_stops_the_program_and_its_workers()
    {
        // Each worker sleeps through the SIGTERM that asks it to stop, so it has to be killed
        $this->write('main.gaz', "\$n = workers(2);\necho \"worker \" .. \$n;\nsleep(60);\n");
        $this->watch();
        $this->waitFor('stdout', 'worker 1');
        $this->waitFor('stdout', 'worker 2');
        $this->assertCount(4, $this->leftRunning(), 'the supervisor, the master and two workers');

        proc_terminate($this->supervisor, SIGTERM);
        $status = $this->waitForExit();
        $this->assertSame([false, true, SIGTERM], [$status['running'], $status['signaled'], $status['termsig']]);
        $this->assertSame([], $this->leftRunning());
    }
}
