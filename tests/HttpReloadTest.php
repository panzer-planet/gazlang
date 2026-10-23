<?php

namespace GazLang\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Reloading a workers() server: SIGUSR2 to its master starts the program again beside the pool and
 * hands it the listeners, so new code is served without a connection refused (workers.c,
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
        $deadline = self::clock() + 15;
        for ($i = 0; count(array_filter($bodies, fn ($b) => $b === 'v2')) < 30 && self::clock() < $deadline; $i++) {
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
            'another port' => ['socket_listen("127.0.0.1", 0)', 'socket_listen("127.0.0.1", FREE)', 'reload failed: the new program serves another address than this one'],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_reload_to_code_that_fails_to_start_keeps_the_old_pool_serving(string $from, string $to, string $logged): void
    {
        [$master, $port] = $this->start('v1');
        $broken = str_replace(['VERSION', 'SETUP', $from], ['v2', '', str_replace('FREE', (string) self::freePort(), $to)], self::SERVER);
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

    public function test_a_reload_hands_down_every_listener(): void
    {
        [$master, $port] = $this->start('v1', '$admin = socket_listen("127.0.0.1", 0); echo "admin on " .. socket_port($admin); flush_output();');
        preg_match('/^admin on (\d+)$/m', (string) file_get_contents($this->dir.'/out'), $m);
        posix_kill(proc_get_status($master)['pid'], SIGUSR2);
        $this->waitForLog('gaz: reloaded: pid ');
        $this->assertSame([$m[0], $m[0]], array_values(array_filter(explode("\n", (string) file_get_contents($this->dir.'/out')), fn ($l) => str_starts_with($l, 'admin on '))));
        $this->assertSame('v1', self::body(self::exchange($port, "GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n")));
    }

    public function test_a_reload_starts_the_program_from_where_it_was_started(): void
    {
        [$master, $port] = $this->start('v1', 'chdir("/");', relative: true);
        $this->write('v2', 'chdir("/");');
        posix_kill(proc_get_status($master)['pid'], SIGUSR2);
        $this->waitForLog('gaz: reloaded: pid ');
        $this->assertSame('v2', self::body(self::exchange($port, "GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n")));
    }

    public function test_a_new_master_that_dies_after_taking_over_gives_the_pool_back(): void
    {
        [$master, $port] = $this->start('v1');
        $this->write('v2');
        posix_kill(proc_get_status($master)['pid'], SIGUSR2);
        $this->waitForLog('gaz: reloaded: pid ');
        posix_kill($this->pids()[0], SIGKILL);
        $this->waitForLog('gaz: taking the pool back');
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame('v1', self::body(self::exchange($port, "GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n")));
        }
        $this->assertTrue(proc_get_status($master)['running']);

        // And it can be reloaded again
        posix_kill(proc_get_status($master)['pid'], SIGUSR2);
        $this->waitForLog('gaz: reloaded: pid ', 2);
        $this->assertSame('v2', self::body(self::exchange($port, "GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n")));
    }

    public function test_systemd_is_told_the_new_main_pid(): void
    {
        $path = '/tmp/gaz-notify-'.getmypid().'-'.bin2hex(random_bytes(4));
        $notify = socket_create(AF_UNIX, SOCK_DGRAM, 0);
        $this->assertNotFalse($notify);
        $this->assertTrue(socket_bind($notify, $path));
        try {
            [$master] = $this->start('v1', env: ['NOTIFY_SOCKET' => $path]);
            $this->write('v2');
            posix_kill(proc_get_status($master)['pid'], SIGUSR2);
            $this->waitForLog('gaz: reloaded: pid ');
            socket_set_option($notify, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);
            $this->assertSame('MAINPID='.$this->pids()[0]."\n", socket_read($notify, 64));
        } finally {
            socket_close($notify);
            @unlink($path);
        }
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
     * A listener handed down by its environment, as a systemd socket unit hands one: a
     * socket_listen() on its address takes it, and one on another binds its own
     *
     * @return array<string, array{string, bool}>
     */
    public static function handedDown(): array
    {
        return [
            'the same address' => ['0', true],
            'another port' => ['FREE', false],
            'not a socket' => ['0', false],
        ];
    }

    #[DataProvider('handedDown')]
    public function test_socket_listen_takes_a_listener_handed_down_by_its_environment(string $port, bool $taken): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $handed = $taken || $port !== '0' ? $server : fopen('/dev/null', 'r');
        $given = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        $port = str_replace('FREE', (string) self::freePort(), $port);
        file_put_contents($this->dir.'/listen.gaz', 'echo socket_port(socket_listen("127.0.0.1", '.$port.'));');
        $out = $this->dir.'/out';
        $process = proc_open([self::binary(), $this->dir.'/listen.gaz'], [['file', '/dev/null', 'r'], ['file', $out, 'w'], ['file', $out, 'a'], 3 => $handed], $pipes, self::ROOT, ['LISTEN_FDS' => '1'] + getenv());
        $this->assertNotFalse($process);
        proc_close($process);
        fclose($server);
        $printed = trim((string) file_get_contents($out));
        $this->assertMatchesRegularExpression('/^\d+$/', $printed);
        if ($taken) {
            $this->assertSame((string) $given, $printed);
        } else {
            $this->assertNotSame((string) $given, $printed);
        }
        if ($port !== '0') {
            $this->assertSame($port, $printed);
        }
    }

    /** A port nothing listens on just now */
    private static function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            throw new \RuntimeException('Cannot find a free port');
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        return $port;
    }

    /**
     * Start the server at a version, with set-up before workers(), from its own directory by a
     * relative path if asked, and give it and its port
     *
     * @param  array<string, string>  $env
     * @return array{resource, int}
     */
    private function start(string $version, string $setup = '', bool $relative = false, array $env = []): array
    {
        $this->write($version, $setup);
        $command = [self::binary(), $relative ? 'server.gaz' : $this->dir.'/server.gaz'];
        $process = proc_open($command, [['file', '/dev/null', 'r'], ['file', $this->dir.'/out', 'w'], ['file', $this->dir.'/log', 'w']], $pipes, $relative ? $this->dir : self::ROOT, $env + getenv());
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
