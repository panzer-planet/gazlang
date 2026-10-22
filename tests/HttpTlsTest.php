<?php

namespace GazLang\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * http::serve() over TLS (its "tls" option, socket_tls_server() and socket_tls_accept() in
 * vm/net.c), in one process and under workers(), where the master hands each connection over as it
 * is accepted and the worker keeps a kept-open TLS connection itself (see docs/http.md). Spoken to
 * by PHP's own TLS streams and by curl, with tests/fixtures/tls's certificate for localhost.
 *
 * Ports vary, so responses are checked by their parts, as HttpServerTest checks them; what the
 * builtins and the option refuse is tests/gaz/sockets/tls_server_test.gaz's and
 * tests/gaz/lib/http_tls_options_test.gaz's, recorded.
 */
class HttpTlsTest extends GazLangTestCase
{
    private const CERTIFICATE = 'tests/fixtures/tls/cert.pem';

    private const TLS = '"tls" => {"certificate" => "tests/fixtures/tls/cert.pem", "key" => "tests/fixtures/tls/server.pem"}';

    /** The handler most tests serve: which worker, the request's scheme and path */
    private const HANDLER = '$r -> ({"body" => "{$n} {$r["scheme"]} {$r["path"]}"})';

    /**
     * Start a server of a few lines run with -e, with some workers (0 for one process; $n is the
     * worker's number, 0 without), http::serve() options (TLS's added unless $tls is false) and a
     * handler, and give it and its port
     *
     * @param  array<string, string>  $env
     * @return array{resource, int}
     */
    private static function startServer(int $workers, string $options = '', string $handler = self::HANDLER, string $log = '/dev/null', bool $tls = true, string $binary = '', array $env = []): array
    {
        $pool = $workers === 0 ? '$n = 0; flush_output();' : "\$n = workers({$workers});";
        $all = implode(', ', array_filter([$tls ? self::TLS : '', $options]));
        $code = 'import "std/http.gaz"; $l = socket_listen("127.0.0.1", 0); echo "listening on " .. socket_port($l); '
            ."{$pool} http::serve(\$l, {$handler}, {{$all}});";
        $server = proc_open([$binary ?: self::binary(), '-e', $code], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', $log, 'w']], $pipes, self::ROOT, $env + getenv());
        if ($server === false) {
            throw new \RuntimeException('Cannot start a server');
        }
        $line = trim((string) fgets($pipes[1]));
        if (! preg_match('/^listening on (\d+)$/', $line, $m)) {
            throw new \RuntimeException("The server never listened: {$line}");
        }

        return [$server, (int) $m[1]];
    }

    /** @param resource $server */
    private static function stop($server): void
    {
        proc_terminate($server);
        for ($wait = 0; proc_get_status($server)['running'] && $wait < 100; $wait++) {
            usleep(50000);
        }
        proc_close($server);
    }

    /**
     * A TLS connection to a port, trusting the fixture's certificate for localhost
     *
     * @param  array<string, mixed>  $ssl
     * @return resource
     */
    private static function connect(int $port, array $ssl = [])
    {
        $context = stream_context_create(['ssl' => $ssl + ['cafile' => self::ROOT.'/'.self::CERTIFICATE, 'peer_name' => 'localhost']]);
        $socket = stream_socket_client("tls://127.0.0.1:{$port}", $errno, $error, 5, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException("Cannot connect over TLS: {$error}");
        }
        stream_set_timeout($socket, 5);

        return $socket;
    }

    /**
     * A plain TCP connection
     *
     * @return resource
     */
    private static function plain(int $port)
    {
        $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 5);
        if ($socket === false) {
            throw new \RuntimeException("Cannot connect: {$error}");
        }
        stream_set_timeout($socket, 5);

        return $socket;
    }

    /**
     * A request on a kept-open connection, and its response read whole: [status, headers by
     * lowercased name, body], or null if the connection closed first
     *
     * @param  resource  $socket
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    private static function ask($socket, string $path, string $headers = ''): ?array
    {
        if (@fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: localhost\r\n{$headers}\r\n") === false) {
            return null;
        }
        $head = '';
        while (! str_ends_with($head, "\r\n\r\n")) {
            $line = fgets($socket);
            if ($line === false) {
                return null;
            }
            $head .= $line;
        }
        $lines = explode("\r\n", trim($head));
        $parsed = [];
        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = explode(':', $line, 2);
            $parsed[strtolower($name)] = trim($value);
        }
        $length = (int) ($parsed['content-length'] ?? 0);
        $body = '';
        while (strlen($body) < $length) {
            $chunk = fread($socket, $length - strlen($body));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $body .= $chunk;
        }

        return ['status' => (int) explode(' ', $lines[0])[1], 'headers' => $parsed, 'body' => $body];
    }

    /**
     * The lines of a log matching a pattern, once there is one (a worker writes its line after the
     * response); fails after 5 seconds
     *
     * @return list<string>
     */
    private static function loggedLines(string $log, string $pattern): array
    {
        for ($wait = 0; $wait < 100; $wait++) {
            $lines = preg_grep($pattern, explode("\n", (string) file_get_contents($log)));
            if ($lines !== [] && $lines !== false) {
                return array_values($lines);
            }
            usleep(50000);
        }
        throw new \RuntimeException("No line matching {$pattern} in the log:\n".file_get_contents($log));
    }

    /** A file for a server's standard error, named for this process, as the parallel rule asks */
    private static function logFile(): string
    {
        return (string) tempnam(sys_get_temp_dir(), 'gazlang-tls');
    }

    /** @return array<string, array{int}> */
    public static function pools(): array
    {
        return ['one process' => [0], 'two workers' => [2]];
    }

    #[DataProvider('pools')]
    public function test_a_server_answers_over_tls_and_keeps_the_connection_open(int $workers)
    {
        $log = self::logFile();
        [$server, $port] = self::startServer($workers, '', self::HANDLER, $log);
        try {
            $socket = self::connect($port);
            $first = self::ask($socket, '/one');
            $this->assertNotNull($first);
            $this->assertSame(200, $first['status']);
            $this->assertMatchesRegularExpression('#^\d https /one$#', $first['body']);
            $this->assertArrayNotHasKey('connection', $first['headers']);
            $second = self::ask($socket, '/two?x=1');
            $this->assertNotNull($second, 'the connection was kept open for a second request');
            // The same worker, which holds the session
            $this->assertSame(explode(' ', $first['body'])[0].' https /two', $second['body']);
            fclose($socket);
            $lines = self::loggedLines($log, '#GET /two\?x=1 200#');
            $this->assertMatchesRegularExpression('#^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ 127\.0\.0\.1 GET /two\?x=1 200 \d+ \d+\.\d{3}ms$#', $lines[0]);
        } finally {
            self::stop($server);
            @unlink($log);
        }
    }

    #[DataProvider('pools')]
    public function test_a_client_speaking_plain_http_is_told_to_ask_for_https(int $workers)
    {
        $log = self::logFile();
        [$server, $port] = self::startServer($workers, '', self::HANDLER, $log);
        try {
            $socket = self::plain($port);
            fwrite($socket, "GET /plain HTTP/1.1\r\nHost: localhost\r\n\r\n");
            $response = (string) stream_get_contents($socket);
            $body = "This port speaks HTTPS: ask for https:// rather than http://.\n";
            $this->assertStringStartsWith("HTTP/1.1 400 Bad Request\r\n", $response);
            $this->assertStringContainsString("\r\nConnection: close\r\n", $response);
            $this->assertStringEndsWith("\r\nContent-Type: text/plain\r\nContent-Length: ".strlen($body)."\r\n\r\n{$body}", $response);
            // Logged, with what the request was unknown: the handshake's reading took it
            self::loggedLines($log, '#^\S+ 127\.0\.0\.1 - - 400 '.strlen($body).' #');
            // And the server serves on
            $this->assertSame(200, self::ask(self::connect($port), '/after')['status'] ?? null);
        } finally {
            self::stop($server);
            @unlink($log);
        }
    }

    #[DataProvider('pools')]
    public function test_curl_is_answered_and_a_client_refusing_the_certificate_ends_nothing(int $workers)
    {
        if (! is_executable('/usr/bin/curl') && trim((string) shell_exec('command -v curl')) === '') {
            $this->markTestSkipped('curl is not installed');
        }
        [$server, $port] = self::startServer($workers);
        try {
            $curl = fn (string ...$args) => (string) shell_exec(implode(' ', array_map('escapeshellarg', ['curl', '-s', '--http1.1', '--max-time', '5', ...$args])).' 2>&1');
            // Without the certificate's issuer it refuses, which the server shrugs off
            $this->assertSame('', $curl("https://localhost:{$port}/refused"));
            $this->assertMatchesRegularExpression('#^\d https /a\d https /b$#', $curl('--cacert', self::ROOT.'/'.self::CERTIFICATE, "https://localhost:{$port}/a", "https://localhost:{$port}/b"));
        } finally {
            self::stop($server);
        }
    }

    public function test_a_handshake_that_never_comes_holds_one_worker_until_the_header_timeout()
    {
        [$server, $port] = self::startServer(2, '"header_timeout" => 1, "request_timeout" => 3');
        try {
            // Connected and quiet, and connected with a few bytes of a ClientHello
            $quiet = self::plain($port);
            $partial = self::plain($port);
            fwrite($partial, "\x16\x03\x01");
            usleep(100000);
            // Each holds a worker for its handshake; a new client waits its turn, then is answered
            $started = microtime(true);
            $answered = self::ask(self::connect($port), '/meanwhile');
            $this->assertSame(200, $answered['status'] ?? null);
            foreach ([$quiet, $partial] as $socket) {
                $closed = microtime(true);
                $this->assertSame('', (string) stream_get_contents($socket), 'closed without a word');
                $this->assertLessThan(2.5, microtime(true) - $started, 'at the header timeout');
            }
            $this->assertLessThan(2.5, microtime(true) - $started);
        } finally {
            self::stop($server);
        }
    }

    public function test_idle_tls_clients_hold_no_worker_a_new_client_needs()
    {
        [$server, $port] = self::startServer(2, '"idle_timeout" => 10');
        try {
            // Three clients asked once and keep their connections open, on two workers
            $idle = [];
            foreach ([0, 1, 2] as $i) {
                $idle[$i] = self::connect($port);
                $this->assertSame(200, self::ask($idle[$i], "/idle{$i}")['status'] ?? null);
            }
            $started = microtime(true);
            $this->assertSame(200, self::ask(self::connect($port), '/new')['status'] ?? null);
            $this->assertLessThan(0.5, microtime(true) - $started, 'answered without waiting out an idle client');
            // Two of the three still have a worker each, at most: one at least gave way, and found its
            // connection closed, as a client does after any idle wait, and one at least kept it
            $kept = array_filter($idle, fn ($socket) => self::ask($socket, '/again') !== null);
            $this->assertGreaterThanOrEqual(1, count($kept));
            $this->assertLessThanOrEqual(2, count($kept));
        } finally {
            self::stop($server);
        }
    }

    public function test_a_free_worker_is_taken_before_an_idle_client_gives_way()
    {
        [$server, $port] = self::startServer(2, '"idle_timeout" => 10');
        try {
            $first = self::connect($port);
            $this->assertSame(200, self::ask($first, '/first')['status'] ?? null);
            // Time for the first one's worker to ask again, holding its idle client
            usleep(100000);
            $second = self::connect($port);
            $this->assertSame(200, self::ask($second, '/second')['status'] ?? null);
            usleep(100000);
            // Two clients, two workers: neither had to give way
            $this->assertSame(200, self::ask($first, '/first-again')['status'] ?? null);
            $this->assertSame(200, self::ask($second, '/second-again')['status'] ?? null);
        } finally {
            self::stop($server);
        }
    }

    public function test_a_renewed_certificate_is_used_from_the_next_connection()
    {
        $dir = self::ROOT.'/tests/.tmp/tls-'.getmypid();
        @mkdir($dir, 0700, true);
        $log = self::logFile();
        [$first, $second] = [self::certificate('first'), self::certificate('second')];
        file_put_contents("{$dir}/cert.pem", $first['certificate']);
        file_put_contents("{$dir}/key.pem", $first['key']);
        $tls = "\"tls\" => {\"certificate\" => \"{$dir}/cert.pem\", \"key\" => \"{$dir}/key.pem\"}";
        [$server, $port] = self::startServer(0, $tls, self::HANDLER, $log, false);
        try {
            $this->assertSame('first', self::presented($port));
            // A half-written renewal is refused, once, and the last good certificate kept
            file_put_contents("{$dir}/cert.pem", substr($second['certificate'], 0, 100));
            $this->assertSame('first', self::presented($port));
            $this->assertSame('first', self::presented($port));
            $this->assertCount(1, self::loggedLines($log, '/still using the certificate read before/'));
            // Renewed, by a rename into place as certbot's symbolic links do
            file_put_contents("{$dir}/key.next", $second['key']);
            file_put_contents("{$dir}/cert.next", $second['certificate']);
            rename("{$dir}/key.next", "{$dir}/key.pem");
            rename("{$dir}/cert.next", "{$dir}/cert.pem");
            $this->assertSame('second', self::presented($port));
        } finally {
            self::stop($server);
            @unlink($log);
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }
    }

    /**
     * A self-signed certificate for localhost, its common name the organisation it says, so a
     * test can tell two apart
     *
     * @return array{certificate: string, key: string}
     */
    private static function certificate(string $name): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $request = openssl_csr_new(['commonName' => 'localhost', 'organizationName' => $name], $key);
        $signed = openssl_csr_sign($request, null, $key, 2);
        openssl_x509_export($signed, $certificate);
        openssl_pkey_export($key, $exported);

        return ['certificate' => $certificate, 'key' => $exported];
    }

    /** The organisation the certificate a server presents names */
    private static function presented(int $port): string
    {
        $socket = self::connect($port, ['verify_peer' => false, 'verify_peer_name' => false, 'capture_peer_cert' => true]);
        $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'];
        fclose($socket);

        return openssl_x509_parse($certificate)['subject']['O'];
    }

    public function test_a_handler_past_its_deadline_over_tls_closes_the_connection_and_is_logged_503()
    {
        $log = self::logFile();
        [$server, $port] = self::startServer(1, '"handler_timeout" => 0.3', '$r -> { sleep(5); return {"body" => "late"}; }', $log);
        try {
            $socket = self::connect($port);
            $started = microtime(true);
            // Nothing can be encrypted from the deadline's signal handler, so the client hears the close
            $this->assertNull(self::ask($socket, '/slow'));
            $this->assertLessThan(2, microtime(true) - $started);
            self::loggedLines($log, '#127\.0\.0\.1 GET /slow 503 0 300\.000ms$#');
            self::loggedLines($log, '/worker 1 timed out on a request; starting another/');
        } finally {
            self::stop($server);
            @unlink($log);
        }
    }

    public function test_a_stop_ends_a_worker_idle_on_a_tls_connection()
    {
        [$server, $port] = self::startServer(1, '"idle_timeout" => 30');
        try {
            $socket = self::connect($port);
            $this->assertSame(200, self::ask($socket, '/kept')['status'] ?? null);
            $started = microtime(true);
            proc_terminate($server);
            $this->assertSame('', (string) stream_get_contents($socket), 'the client sees the end');
            for ($wait = 0; proc_get_status($server)['running'] && $wait < 100; $wait++) {
                usleep(50000);
            }
            $this->assertFalse(proc_get_status($server)['running']);
            $this->assertLessThan(3, microtime(true) - $started);
        } finally {
            self::stop($server);
        }
    }

    public function test_tls_under_workers_runs_under_the_sanitizers()
    {
        // The master's hand-over at accept and the worker's kept TLS connections, with ASan, UBSan
        // and the leak count
        CVM::build();
        $log = self::logFile();
        [$server, $port] = self::startServer(2, '"header_timeout" => 1', self::HANDLER, $log, true, CVM::BINARY, ['GAZVM_STATS' => '1']);
        try {
            $kept = self::connect($port);
            foreach ([1, 2, 3] as $i) {
                $this->assertSame(200, self::ask($kept, "/kept{$i}")['status'] ?? null);
            }
            foreach ([1, 2, 3] as $i) {
                $this->assertSame(200, self::ask(self::connect($port), "/new{$i}")['status'] ?? null);
            }
            $plain = self::plain($port);
            fwrite($plain, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");
            $this->assertStringStartsWith('HTTP/1.1 400', (string) stream_get_contents($plain));
            $junk = self::plain($port);
            fwrite($junk, random_bytes(2000));
            stream_get_contents($junk);
            $quiet = self::plain($port);
            $this->assertSame('', (string) stream_get_contents($quiet));
        } catch (\Throwable $e) {
            $this->fail($e->getMessage()."\n".file_get_contents($log));
        } finally {
            self::stop($server);
        }
        $text = (string) file_get_contents($log);
        @unlink($log);
        $this->assertDoesNotMatchRegularExpression('/ERROR:|runtime error|SUMMARY:/', $text);
        $this->assertSame(2, preg_match_all('/^gazvm: 0 values leaked/m', $text), $text);
    }
}
