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

    // ---- HTTP/2: a raw client, so frames no library would send can be sent too ----

    private const DATA = 0;

    private const HEADERS = 1;

    private const RST_STREAM = 3;

    private const SETTINGS = 4;

    private const PING = 6;

    private const GOAWAY = 7;

    private const WINDOW_UPDATE = 8;

    private const END_STREAM = 1;

    private const END_HEADERS = 4;

    /** RFC 7541's static table's names, which the server's header blocks name by index */
    private const STATIC_NAMES = [':authority', ':method', ':method', ':path', ':path', ':scheme', ':scheme', ':status', ':status',
        ':status', ':status', ':status', ':status', ':status', 'accept-charset', 'accept-encoding', 'accept-language', 'accept-ranges',
        'accept', 'access-control-allow-origin', 'age', 'allow', 'authorization', 'cache-control', 'content-disposition',
        'content-encoding', 'content-language', 'content-length', 'content-location', 'content-range', 'content-type', 'cookie',
        'date', 'etag', 'expect', 'expires', 'from', 'host', 'if-match', 'if-modified-since', 'if-none-match', 'if-range',
        'if-unmodified-since', 'last-modified', 'link', 'location', 'max-forwards', 'proxy-authenticate', 'proxy-authorization',
        'range', 'referer', 'refresh', 'retry-after', 'server', 'set-cookie', 'strict-transport-security', 'transfer-encoding',
        'user-agent', 'vary', 'via', 'www-authenticate'];

    /** The values of the static table's :status entries, which a response may name whole */
    private const STATIC_STATUSES = [8 => '200', 9 => '204', 10 => '206', 11 => '304', 12 => '400', 13 => '404', 14 => '500'];

    /**
     * An HTTP/2 connection, asked for by ALPN, its preface and $settings sent
     *
     * @param  array<int, int>  $settings
     * @return resource
     */
    private static function h2(int $port, array $settings = [])
    {
        $socket = self::connect($port, ['alpn_protocols' => 'h2']);
        $payload = '';
        foreach ($settings as $id => $value) {
            $payload .= pack('nN', $id, $value);
        }
        fwrite($socket, "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n".self::frame(self::SETTINGS, 0, 0, $payload));

        return $socket;
    }

    private static function frame(int $type, int $flags, int $stream, string $payload = ''): string
    {
        return substr(pack('N', strlen($payload)), 1).chr($type).chr($flags).pack('N', $stream).$payload;
    }

    /**
     * The next frame, as [type, flags, stream, payload], or null once the connection ends
     *
     * @param  resource  $socket
     * @return array{int, int, int, string}|null
     */
    private static function nextFrame($socket): ?array
    {
        $head = self::readExactly($socket, 9);
        if ($head === null) {
            return null;
        }
        $length = unpack('N', "\0".substr($head, 0, 3))[1];
        $payload = $length > 0 ? self::readExactly($socket, $length) : '';

        return $payload === null ? null : [ord($head[3]), ord($head[4]), unpack('N', substr($head, 5))[1] & 0x7FFFFFFF, $payload];
    }

    /** @param resource $socket */
    private static function readExactly($socket, int $n): ?string
    {
        $bytes = '';
        while (strlen($bytes) < $n) {
            $chunk = fread($socket, $n - strlen($bytes));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    /**
     * A request's header block: each field a literal not indexed, nothing Huffman-coded
     *
     * @param  list<array{string, string}>  $fields
     */
    private static function block(array $fields): string
    {
        $block = '';
        foreach ($fields as [$name, $value]) {
            $block .= "\0".self::text($name).self::text($value);
        }

        return $block;
    }

    private static function text(string $text): string
    {
        $length = strlen($text);
        if ($length < 127) {
            return chr($length).$text;
        }
        $bytes = chr(127);
        for ($n = $length - 127; $n >= 128; $n >>= 7) {
            $bytes .= chr(($n & 127) | 128);
        }

        return $bytes.chr($n);
    }

    /**
     * A GET request's HEADERS frame on a stream
     *
     * @param  list<array{string, string}>  $extra
     */
    private static function getFrame(int $stream, string $path, array $extra = []): string
    {
        $fields = [[':method', 'GET'], [':scheme', 'https'], [':path', $path], [':authority', 'localhost'], ...$extra];

        return self::frame(self::HEADERS, self::END_HEADERS | self::END_STREAM, $stream, self::block($fields));
    }

    /**
     * The server's header blocks decoded: what it writes is indexed :status entries, and literals
     * not indexed with a name indexed or written out, nothing Huffman-coded (http2::encode())
     *
     * @return array<string, string>
     */
    private static function fields(string $block): array
    {
        $fields = [];
        $at = 0;
        while ($at < strlen($block)) {
            $byte = ord($block[$at]);
            if ($byte >= 128) {
                $fields[':status'] = self::STATIC_STATUSES[$byte & 127];
                $at++;

                continue;
            }
            [$index, $at] = self::integer($block, $at, 4);
            [$name, $at] = $index === 0 ? self::string($block, $at) : [self::STATIC_NAMES[$index - 1], $at];
            [$value, $at] = self::string($block, $at);
            $fields[$name] = $value;
        }

        return $fields;
    }

    /** @return array{int, int} */
    private static function integer(string $block, int $at, int $prefix): array
    {
        $most = (1 << $prefix) - 1;
        $n = ord($block[$at++]) & $most;
        if ($n < $most) {
            return [$n, $at];
        }
        for ($shift = 0; ; $shift += 7) {
            $byte = ord($block[$at++]);
            $n += ($byte & 127) << $shift;
            if ($byte < 128) {
                return [$n, $at];
            }
        }
    }

    /** @return array{string, int} */
    private static function string(string $block, int $at): array
    {
        [$length, $at] = self::integer($block, $at, 7);

        return [substr($block, $at, $length), $at + $length];
    }

    /**
     * Frames read until each of $streams has its whole response, answering SETTINGS and giving
     * room back as DATA comes: [stream => [status, headers, body]], and every other frame seen
     *
     * @param  resource  $socket
     * @param  list<int>  $streams
     * @return array{array<int, array{int, array<string, string>, string}>, list<array{int, int, int, string}>}
     */
    private static function responses($socket, array $streams): array
    {
        $responses = [];
        $others = [];
        $open = array_flip($streams);
        while ($open !== []) {
            $frame = self::nextFrame($socket);
            if ($frame === null) {
                break;
            }
            [$type, $flags, $stream, $payload] = $frame;
            if ($type === self::SETTINGS && ! ($flags & 1)) {
                fwrite($socket, self::frame(self::SETTINGS, 1, 0));
            }
            if ($type === self::HEADERS && isset($open[$stream])) {
                $fields = self::fields($payload);
                $responses[$stream] = [(int) $fields[':status'], $fields, ''];
            } elseif ($type === self::DATA && isset($open[$stream])) {
                // A test that read the HEADERS itself gives only the rest
                $responses[$stream] ??= [0, [], ''];
                $responses[$stream][2] .= $payload;
                if ($payload !== '') {
                    fwrite($socket, self::frame(self::WINDOW_UPDATE, 0, 0, pack('N', strlen($payload))).self::frame(self::WINDOW_UPDATE, 0, $stream, pack('N', strlen($payload))));
                }
            } else {
                $others[] = $frame;
            }
            if (isset($open[$stream]) && ($type === self::HEADERS || $type === self::DATA) && ($flags & self::END_STREAM)) {
                unset($open[$stream]);
            }
        }

        return [$responses, $others];
    }

    private static function curlHasHttp2(): bool
    {
        return str_contains((string) shell_exec('curl -V 2>/dev/null'), 'HTTP2');
    }

    #[DataProvider('pools')]
    public function test_http2_is_spoken_to_a_client_that_asks_for_it(int $workers)
    {
        $log = self::logFile();
        [$server, $port] = self::startServer($workers, '', '$r -> ({"headers" => {"X-Method" => $r["method"]}, "body" => "{$r["scheme"]} {$r["path"]} {$r["query"]} {$r["headers"]["host"]} " .. len($r["body"])})', $log);
        try {
            $socket = self::h2($port);
            $this->assertSame('h2', stream_get_meta_data($socket)['crypto']['alpn_protocol']);
            // Three streams at once, answered each on its own
            fwrite($socket, self::getFrame(1, '/one?a=1').self::getFrame(3, '/two').self::getFrame(5, '/three'));
            [$responses] = self::responses($socket, [1, 3, 5]);
            $this->assertSame([200, 'https /one a=1 localhost 0'], [$responses[1][0], $responses[1][2]]);
            $this->assertSame('https /two  localhost 0', $responses[3][2]);
            $this->assertSame('https /three  localhost 0', $responses[5][2]);
            $this->assertSame('GET', $responses[1][1]['x-method'], 'a handler\'s header, its name lowercased');
            $this->assertSame((string) strlen($responses[1][2]), $responses[1][1]['content-length']);
            $this->assertArrayHasKey('date', $responses[1][1]);
            // A body over several DATA frames
            $post = [[':method', 'POST'], [':scheme', 'https'], [':path', '/upload'], [':authority', 'localhost']];
            fwrite($socket, self::frame(self::HEADERS, self::END_HEADERS, 7, self::block($post))
                .self::frame(self::DATA, 0, 7, str_repeat('a', 1000)).self::frame(self::DATA, self::END_STREAM, 7, str_repeat('b', 500)));
            [$responses] = self::responses($socket, [7]);
            $this->assertSame('https /upload  localhost 1500', $responses[7][2]);
            self::loggedLines($log, '#127\.0\.0\.1 GET /one\?a=1 200 \d+ \d+\.\d{3}ms$#');
            self::loggedLines($log, '#127\.0\.0\.1 POST /upload 200 \d+ #');
            fclose($socket);
            if (self::curlHasHttp2()) {
                $out = (string) shell_exec('curl -s --http2 --max-time 5 --cacert '.escapeshellarg(self::ROOT.'/'.self::CERTIFICATE)." -w ' %{http_version}' https://localhost:{$port}/curl");
                $this->assertSame('https /curl  localhost:'.$port.' 0 2', $out);
            }
        } finally {
            self::stop($server);
            @unlink($log);
        }
    }

    public function test_http2_flow_control_holds_a_response_until_the_client_has_room()
    {
        [$server, $port] = self::startServer(1, '', '$r -> ({"body" => repeat("x", 100000)})');
        try {
            // A window of 10 bytes: the response's first DATA frame is 10 bytes, and nothing more comes
            $socket = self::h2($port, [4 => 10]);
            fwrite($socket, self::getFrame(1, '/big'));
            $data = '';
            while (true) {
                [$type, $flags, $stream, $payload] = self::nextFrame($socket);
                if ($type === self::SETTINGS && ! ($flags & 1)) {
                    fwrite($socket, self::frame(self::SETTINGS, 1, 0));
                }
                if ($type === self::DATA) {
                    $data .= $payload;
                    break;
                }
            }
            $this->assertSame(10, strlen($data));
            stream_set_timeout($socket, 0, 300000);
            $this->assertNull(self::nextFrame($socket), 'no more until the window grows');
            stream_set_timeout($socket, 5);
            // Room for the rest, on the stream and the connection: it comes, and ends the stream
            fwrite($socket, self::frame(self::WINDOW_UPDATE, 0, 1, pack('N', 200000)).self::frame(self::WINDOW_UPDATE, 0, 0, pack('N', 200000)));
            [$responses] = self::responses($socket, [1]);
            $this->assertSame(100000, strlen($data.$responses[1][2]));
        } finally {
            self::stop($server);
        }
    }

    public function test_http2_answers_a_ping_and_resets_a_malformed_request_but_serves_on()
    {
        [$server, $port] = self::startServer(1);
        try {
            $socket = self::h2($port);
            fwrite($socket, self::frame(self::PING, 0, 0, 'abcdefgh'));
            // An uppercase name is malformed in HTTP/2
            fwrite($socket, self::getFrame(1, '/bad', [['X-Upper', 'no']]).self::getFrame(3, '/good'));
            [$responses, $others] = self::responses($socket, [3]);
            $this->assertStringEndsWith('https /good', $responses[3][2]);
            $this->assertContains([self::PING, 1, 0, 'abcdefgh'], $others, 'a PING comes back acknowledged');
            $this->assertContains([self::RST_STREAM, 0, 1, pack('N', 1)], $others, 'the malformed stream is reset, PROTOCOL_ERROR');
        } finally {
            self::stop($server);
        }
    }

    public function test_http2_says_goaway_after_requests_per_connection()
    {
        [$server, $port] = self::startServer(0, '"requests_per_connection" => 2');
        try {
            $socket = self::h2($port);
            fwrite($socket, self::getFrame(1, '/a').self::getFrame(3, '/b'));
            [$responses, $others] = self::responses($socket, [1, 3]);
            $this->assertCount(2, $responses);
            // GOAWAY, the last stream it took being 3, no error
            for ($frame = self::nextFrame($socket); $frame !== null && $frame[0] !== self::GOAWAY; $frame = self::nextFrame($socket)) {
                $others[] = $frame;
            }
            $this->assertSame([self::GOAWAY, 0, 0, pack('NN', 3, 0)], $frame);
            fclose($socket);
            // And a new connection is served
            $again = self::h2($port);
            fwrite($again, self::getFrame(1, '/again'));
            $this->assertStringEndsWith('/again', self::responses($again, [1])[0][1][2]);
        } finally {
            self::stop($server);
        }
    }

    #[DataProvider('pools')]
    public function test_an_idle_http2_connection_gives_way_to_a_new_client(int $workers)
    {
        [$server, $port] = self::startServer(max($workers - 1, 0), '"idle_timeout" => 10');
        try {
            $idle = self::h2($port);
            fwrite($idle, self::getFrame(1, '/idle'));
            self::responses($idle, [1]);
            usleep(100000);
            $started = microtime(true);
            $this->assertSame(200, self::ask(self::connect($port), '/new')['status'] ?? null);
            $this->assertLessThan(0.5, microtime(true) - $started);
            // The idle one was told GOAWAY, its last stream 1, before its connection closed
            $seen = [];
            for ($frame = self::nextFrame($idle); $frame !== null; $frame = self::nextFrame($idle)) {
                $seen[] = $frame;
            }
            $this->assertContains([self::GOAWAY, 0, 0, pack('NN', 1, 0)], $seen);
        } finally {
            self::stop($server);
        }
    }

    public function test_http2_can_be_left_out_of_what_alpn_offers()
    {
        [$server, $port] = self::startServer(0, '', self::HANDLER, '/dev/null', false, '', []);
        self::stop($server);
        [$server, $port] = self::startServer(0, '"tls" => {"certificate" => "tests/fixtures/tls/cert.pem", "key" => "tests/fixtures/tls/server.pem", "http2" => false}', self::HANDLER, '/dev/null', false);
        try {
            $socket = self::connect($port, ['alpn_protocols' => 'h2,http/1.1']);
            $this->assertSame('http/1.1', stream_get_meta_data($socket)['crypto']['alpn_protocol']);
            $this->assertSame(200, self::ask($socket, '/one')['status'] ?? null);
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
            // HTTP/2: streams at once, a body, flow control's room given back, a PING
            $h2 = self::h2($port, [4 => 100]);
            fwrite($h2, self::getFrame(1, '/h2a').self::getFrame(3, '/h2b').self::frame(self::PING, 0, 0, '12345678'));
            $post = [[':method', 'POST'], [':scheme', 'https'], [':path', '/h2c'], [':authority', 'localhost']];
            fwrite($h2, self::frame(self::HEADERS, self::END_HEADERS, 5, self::block($post)).self::frame(self::DATA, self::END_STREAM, 5, str_repeat('z', 5000)));
            [$responses] = self::responses($h2, [1, 3, 5]);
            $this->assertCount(3, $responses);
            fclose($h2);
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
