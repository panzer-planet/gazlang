<?php

namespace GazLang\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Reloading a workers() server: SIGUSR2 to its master starts the program again beside the pool and
 * hands it the listener, so new code is served without a connection refused (workers.c,
 * "Reloading"). Each test writes a small server to a directory of its own, changes the file, and
 * signals; the new masters are found by the pid their "reloading" line names.
 */
class HttpReloadTest extends GazLangTestCase
{
    /** A server answering every request with VERSION, set up before workers() by SETUP */
    private const SERVER = <<<'GAZ'
        import "std/http.gaz";
        $listener = socket_listen("127.0.0.1", 0);
        echo "listening on " .. socket_port($listener);
        flush_output();
        SETUP
        workers(2);
        http::serve($listener, $request -> ({"body" => "VERSION"}), {"access_log" => false});
        GAZ;

    private string $dir = '';

    /** @var list<resource> */
    private array $processes = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/gazlang-reload-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ($this->pids() as $pid) {
            posix_kill($pid, SIGKILL);
        }
        foreach ($this->processes as $process) {
            proc_terminate($process, SIGKILL);
            proc_close($process);
        }
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function test_a_reload_serves_the_new_code_without_refusing_or_resetting_a_connection(): void
    {
        [$master, $port] = $this->start('v1');
        // A worker ignores SIGUSR2, which is its master's
        foreach ($this->children($master) as $worker) {
            posix_kill($worker, SIGUSR2);
        }
        // One connection kept open between requests, and one halfway through a head, across the reload
        $kept = $this->connect($port);
        fwrite($kept, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");
        $this->assertSame('v1', self::body(self::readResponse($kept)));
        $half = $this->connect($port);
        fwrite($half, "GET / HTTP/1.1\r\n");

        $bodies = [];
        $deadline = microtime(true) + 15;
        for ($i = 0; count(array_filter($bodies, fn ($b) => $b === 'v2')) < 30 && microtime(true) < $deadline; $i++) {
            if ($i === 10) {
                $this->write('v2');
                posix_kill(proc_get_status($master)['pid'], SIGUSR2);
            }
            $bodies[] = self::body(self::exchange($port, "GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n"));
        }
        $this->assertNotContains('', $bodies, 'a connection was refused or reset');
        $first = array_search('v2', $bodies, true);
        $this->assertNotFalse($first, 'the new code never answered');
        $this->assertSame([], array_values(array_filter(array_slice($bodies, (int) $first), fn ($b) => $b !== 'v2')), 'the old code answered after the new');

        fwrite($kept, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");
        $this->assertSame('v2', self::body(self::readResponse($kept)));
        fwrite($half, "Host: x\r\n\r\n");
        $this->assertSame('v2', self::body(self::readResponse($half)));

        $status = self::ended($master);
        $this->assertFalse($status['running'], 'the old master is still running');
        $this->assertSame(0, $status['exitcode']);
        $log = $this->log();
        $this->assertStringContainsString('gaz: reloaded: pid ', $log);
        $this->assertStringNotContainsString('recycled', $log);
    }

    /**
     * New code that can't start, in each way it can fail: a change to the server, and what the log says
     *
     * @return array<string, array{string, string, string}>
     */
    public static function failures(): array
    {
        return [
            'an error before workers()' => ['workers(2);', 'throw "the new code is broken";', 'reload failed: the new program exited with code 1 before it was ready'],
            'a worker that dies at once' => ['workers(2);', "workers(2);\nexit(7);", 'reload failed: the new program exited with code 7 before it was ready'],
            'another port' => ['socket_listen("127.0.0.1", 0)', 'socket_listen("127.0.0.1", 1)', 'the listener handed down by LISTEN_FDS is on 127.0.0.1 port '],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_reload_to_code_that_fails_to_start_keeps_the_old_pool_serving(string $from, string $to, string $logged): void
    {
        [$master, $port] = $this->start('v1');
        $broken = str_replace(['VERSION', 'SETUP', $from], ['v2', '', $to], self::SERVER);
        file_put_contents($this->dir.'/server.gaz', $broken);
        posix_kill(proc_get_status($master)['pid'], SIGUSR2);
        $this->waitForLog('still serving the old code');
        $this->assertStringContainsString($logged, $this->log());
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame('v1', self::body(self::exchange($port, "GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n")));
        }
        $this->assertTrue(proc_get_status($master)['running']);

        // A failed reload leaves nothing in the way of the next
        $this->write('v3');
        posix_kill(proc_get_status($master)['pid'], SIGUSR2);
        $this->waitForLog('gaz: reloaded: pid ');
        $this->assertSame('v3', self::body(self::exchange($port, "GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n")));
    }

    public function test_a_reload_twice_in_a_row(): void
    {
        [$master, $port] = $this->start('v1');
        $this->write('v2');
        posix_kill(proc_get_status($master)['pid'], SIGUSR2);
        $this->waitForLog('gaz: reloaded: pid ');
        $this->assertSame('v2', self::body(self::exchange($port, "GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n")));
        $second = $this->pids()[0];
        $this->write('v3');
        posix_kill($second, SIGUSR2);
        $this->waitForLog('gaz: reloaded: pid ', 2);
        $this->assertSame('v3', self::body(self::exchange($port, "GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n")));
        $this->assertFalse(self::ended($master)['running']);
        for ($wait = 0; posix_kill($second, 0) && $wait < 100; $wait++) {
            usleep(50000);
        }
        $this->assertFalse(posix_kill($second, 0), 'the second master is still running');
    }

    public function test_a_stop_while_the_new_program_starts_ends_both(): void
    {
        [$master, $port] = $this->start('v1');
        $this->write('v2', 'sleep(3);');
        posix_kill(proc_get_status($master)['pid'], SIGUSR2);
        $this->waitForLog('gaz: reloading: starting');
        $new = $this->pids()[0];
        proc_terminate($master, SIGTERM);
        $this->assertFalse(self::ended($master)['running']);
        for ($wait = 0; posix_kill($new, 0) && $wait < 100; $wait++) {
            usleep(50000);
        }
        $this->assertFalse(posix_kill($new, 0), 'the new program outlived the stop');
        $this->assertFalse(self::listens($port));
    }

    public function test_sigterm_to_the_new_master_still_stops_it(): void
    {
        [$master, $port] = $this->start('v1');
        $this->write('v2');
        posix_kill(proc_get_status($master)['pid'], SIGUSR2);
        $this->waitForLog('gaz: reloaded: pid ');
        $this->assertFalse(self::ended($master)['running']);
        $new = $this->pids()[0];
        posix_kill($new, SIGTERM);
        for ($wait = 0; posix_kill($new, 0) && $wait < 100; $wait++) {
            usleep(50000);
        }
        $this->assertFalse(posix_kill($new, 0));
        $this->assertFalse(self::listens($port));
    }

    /**
     * A listener handed down by its environment, as a systemd socket unit hands one: the first
     * socket_listen() on its address takes it
     *
     * @return array<string, array{string, string}>
     */
    public static function handedDown(): array
    {
        return [
            'the same address' => ['0', 'PORT'],
            'another port' => ['1', 'socket_listen(): the listener handed down by LISTEN_FDS is on 127.0.0.1 port PORT, not 127.0.0.1 port 1'],
            'not a socket' => ['0', "socket_listen(): descriptor 3, handed down by LISTEN_FDS, isn't a listening socket"],
        ];
    }

    #[DataProvider('handedDown')]
    public function test_socket_listen_takes_a_listener_handed_down_by_its_environment(string $port, string $expected): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $handed = str_contains($expected, 'descriptor 3') ? fopen('/dev/null', 'r') : $server;
        $given = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        file_put_contents($this->dir.'/listen.gaz', 'echo socket_port(socket_listen("127.0.0.1", '.$port.'));');
        $out = $this->dir.'/out';
        $process = proc_open([self::binary(), $this->dir.'/listen.gaz'], [['file', '/dev/null', 'r'], ['file', $out, 'w'], ['file', $out, 'a'], 3 => $handed], $pipes, self::ROOT, ['LISTEN_FDS' => '1'] + getenv());
        $this->assertNotFalse($process);
        proc_close($process);
        fclose($server);
        $this->assertStringContainsString(str_replace('PORT', (string) $given, $expected), (string) file_get_contents($out));
    }

    /**
     * Start the server at a version, and give it and its port
     *
     * @return array{resource, int}
     */
    private function start(string $version): array
    {
        $this->write($version);
        $process = proc_open([self::binary(), $this->dir.'/server.gaz'], [['file', '/dev/null', 'r'], ['file', $this->dir.'/out', 'w'], ['file', $this->dir.'/log', 'w']], $pipes, self::ROOT);
        if ($process === false) {
            throw new \RuntimeException('Cannot start the server');
        }
        $this->processes[] = $process;
        for ($wait = 0; $wait < 100; $wait++) {
            if (preg_match('/^listening on (\d+)$/m', (string) file_get_contents($this->dir.'/out'), $m)) {
                $port = (int) $m[1];
                // Listening comes before workers(): wait for a worker to answer
                for ($tries = 0; $tries < 100 && self::body(self::exchange($port, "GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n")) !== $version; $tries++) {
                    usleep(50000);
                }

                return [$process, $port];
            }
            usleep(50000);
        }
        throw new \RuntimeException('The server never listened: '.$this->log());
    }

    /** The server's file at a version, with set-up before workers() */
    private function write(string $version, string $setup = ''): void
    {
        file_put_contents($this->dir.'/server.gaz', str_replace(['VERSION', 'SETUP'], [$version, $setup], self::SERVER));
    }

    private function log(): string
    {
        return (string) @file_get_contents($this->dir.'/log');
    }

    /** Wait until the log says something, count times; fails after 10 seconds */
    private function waitForLog(string $text, int $count = 1): void
    {
        for ($wait = 0; $wait < 200; $wait++) {
            if (substr_count($this->log(), $text) >= $count) {
                return;
            }
            usleep(50000);
        }
        $this->fail("The log never said {$text}:\n".$this->log());
    }

    /**
     * The new masters the log names, newest first
     *
     * @return list<int>
     */
    private function pids(): array
    {
        preg_match_all('/starting the new program \(pid (\d+)\)/', $this->log(), $m);

        return array_reverse(array_map('intval', $m[1]));
    }

    /**
     * A process's children
     *
     * @param  resource  $process
     * @return list<int>
     */
    private function children($process): array
    {
        $pid = proc_get_status($process)['pid'];
        $out = (string) shell_exec('pgrep -P '.(int) $pid);

        return array_map('intval', array_filter(explode("\n", trim($out))));
    }

    /** @return resource */
    private function connect(int $port)
    {
        $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 2);
        if ($socket === false) {
            throw new \RuntimeException("Cannot connect: {$error}");
        }
        stream_set_timeout($socket, 5);

        return $socket;
    }

    /** One request on a connection of its own, and what came back; '' if it was refused or reset */
    private static function exchange(int $port, string $request): string
    {
        set_error_handler(fn () => true);
        try {
            $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 2);
            if ($socket === false) {
                return '';
            }
            stream_set_timeout($socket, 5);
            fwrite($socket, $request);
            $response = (string) stream_get_contents($socket);
            fclose($socket);

            return $response;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * One response off a connection that stays open
     *
     * @param  resource  $socket
     */
    private static function readResponse($socket): string
    {
        $head = '';
        while (! str_ends_with($head, "\r\n\r\n")) {
            $line = fgets($socket);
            if ($line === false) {
                throw new \RuntimeException("The connection ended in a response's head: {$head}");
            }
            $head .= $line;
        }
        preg_match('/^content-length: *(\d+)/mi', $head, $m);
        $length = (int) ($m[1] ?? 0);

        return $head.($length > 0 ? (string) fread($socket, $length) : '');
    }

    /** A 200's body, or '' for anything else */
    private static function body(string $response): string
    {
        [$head, $body] = explode("\r\n\r\n", $response, 2) + [1 => ''];

        return str_starts_with($head, 'HTTP/1.1 200') ? $body : '';
    }

    /**
     * @param  resource  $process
     * @return array<string, mixed>
     */
    private static function ended($process): array
    {
        for ($wait = 0; ($status = proc_get_status($process))['running'] && $wait < 100; $wait++) {
            usleep(50000);
        }

        return $status;
    }

    private static function listens(int $port): bool
    {
        set_error_handler(fn () => true);
        try {
            $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 1);
        } finally {
            restore_error_handler();
        }

        return $socket !== false;
    }
}
