<?php

namespace GazLang\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The server half: socket_listen(), socket_accept(), socket_port(), workers() and http::serve(),
 * through tests/programs/web_server.gaz, which is asked over raw sockets so that requests no
 * client would send can be sent too.
 *
 * The ports are whatever is free and the workers answer in whatever order the system hands them
 * connections, so responses are checked by their parts rather than recorded; what is refused
 * before anything listens runs through executeCode(), and is recorded.
 */
class HttpServerTest extends GazLangTestCase
{
    /** The shared server's port */
    private static int $port = 0;

    /** @var resource|null */
    private static $server = null;

    /** Where the shared server's standard error goes */
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        self::$log = (string) tempnam(sys_get_temp_dir(), 'gazlang-server');
        [self::$server, self::$port] = self::startServer(2, self::$log);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        @unlink(self::$log);
    }

    /**
     * Start web_server.gaz with some workers, standard error to a file, and give it and its port
     *
     * @return array{resource, int}
     */
    private static function startServer(int $workers, string $log, ?int $maxRequests = null): array
    {
        $args = ['0', (string) $workers];
        if ($maxRequests !== null) {
            $args[] = (string) $maxRequests;
        }
        $server = proc_open([self::binary(), '-f', 'tests/programs/web_server.gaz', '--', ...$args], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', $log, 'w']], $pipes, self::ROOT);
        if ($server === false) {
            throw new \RuntimeException('Cannot start tests/programs/web_server.gaz');
        }
        $line = trim((string) fgets($pipes[1]));
        if (! preg_match('/^listening on (\d+)$/', $line, $m)) {
            throw new \RuntimeException("web_server.gaz never listened: {$line}");
        }

        return [$server, (int) $m[1]];
    }

    /**
     * Start a server of a few lines run with -e, with some workers, http::serve() options, a
     * handler written as GazLang (answering "ok" to everything unless given) and what each worker
     * does before it serves, and give it and its port
     *
     * @return array{resource, int}
     */
    private static function startSmallServer(int $workers, string $options, string $handler = '$r -> ({"body" => "ok"})', string $startup = '', string $log = '/dev/null'): array
    {
        $code = 'import "std/http.gaz"; $l = socket_listen("127.0.0.1", 0); echo "listening on " .. socket_port($l); '
            ."workers({$workers}); {$startup} http::serve(\$l, {$handler}, {$options});";
        $server = proc_open([self::binary(), '-e', $code], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', $log, 'w']], $pipes, self::ROOT);
        if ($server === false) {
            throw new \RuntimeException('Cannot start a server');
        }
        $line = trim((string) fgets($pipes[1]));
        if (! preg_match('/^listening on (\d+)$/', $line, $m)) {
            throw new \RuntimeException("The server never listened: {$line}");
        }

        return [$server, (int) $m[1]];
    }

    /**
     * A connection to a port, waiting 5 seconds at most for each read
     *
     * @return resource
     */
    private static function connect(?int $port = null)
    {
        $socket = stream_socket_client('tcp://127.0.0.1:'.($port ?? self::$port), $errno, $error, 5);
        if ($socket === false) {
            throw new \RuntimeException("Cannot connect: {$error}");
        }
        stream_set_timeout($socket, 5);

        return $socket;
    }

    /**
     * Send bytes, say nothing more will come, and give everything that comes back until the server
     * closes: a connection is kept open for another request until the client is done
     */
    private static function exchange(string $request, ?int $port = null): string
    {
        $socket = self::connect($port);
        fwrite($socket, $request);
        stream_socket_shutdown($socket, STREAM_SHUT_WR);
        $response = (string) stream_get_contents($socket);
        fclose($socket);

        return $response;
    }

    /**
     * One response read off a connection that stays open: its head, then as many bytes as its
     * Content-Length says
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
        $length = (int) (self::response($head)['headers']['content-length'] ?? 0);

        return $head.($length > 0 ? (string) fread($socket, $length) : '');
    }

    /**
     * Seconds until the server closes a connection, and what it sent before it did
     *
     * @param  resource  $socket
     * @return array{float, string}
     */
    private static function untilClosed($socket): array
    {
        $start = microtime(true);
        $rest = (string) stream_get_contents($socket);

        return [microtime(true) - $start, $rest];
    }

    /**
     * Wait for a process to end, and give its status
     *
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

    /**
     * The access log's lines in a log matching a pattern, once there is one: a worker writes its
     * line after the response, so the client may read the response first. Fails after 5 seconds.
     *
     * @return list<string>
     */
    private static function loggedLine(string $log, string $pattern): array
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

    /**
     * A response in parts: status, headers by lowercased name, body
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function response(string $response): array
    {
        [$head, $body] = explode("\r\n\r\n", $response, 2) + [1 => ''];
        $lines = explode("\r\n", $head);
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower($name)] = trim($value);
        }

        return ['status' => (int) explode(' ', $lines[0])[1], 'headers' => $headers, 'body' => $body];
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function get(string $path, string $method = 'GET'): array
    {
        return self::response(self::exchange("{$method} {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n"));
    }

    public function test_it_writes_what_the_handler_returns()
    {
        $response = self::get('/');

        $this->assertSame(200, $response['status']);
        $this->assertMatchesRegularExpression('/^hello from worker [12]\n$/', $response['body']);
        $this->assertSame('text/plain', $response['headers']['content-type']);
        $this->assertSame('20', $response['headers']['content-length']);
        // Kept open, which HTTP/1.1 assumes without a header
        $this->assertArrayNotHasKey('connection', $response['headers']);
        // The Date header is time() as HTTP writes it, give or take the moment it took
        $date = strtotime($response['headers']['date']);
        $this->assertSame(gmdate('D, d M Y H:i:s', $date).' GMT', $response['headers']['date']);
        $this->assertLessThan(5, abs(time() - $date));
    }

    public function test_a_status_line_has_its_reason()
    {
        $this->assertStringStartsWith("HTTP/1.1 404 Not Found\r\n", self::exchange("GET /nowhere HTTP/1.1\r\nHost: x\r\n\r\n"));
    }

    public function test_the_handler_sees_the_method_path_query_headers_and_body()
    {
        $request = json_decode(self::get('/echo?a=1&b=%20')['body'], true);
        $this->assertSame(['method' => 'GET', 'path' => '/echo', 'query' => 'a=1&b=%20', 'headers' => ['host' => 'localhost'], 'body' => '', 'remote_address' => '127.0.0.1'], $request);

        $response = self::exchange("POST /echo HTTP/1.1\r\nHost: localhost\r\nX-Twice: a\r\nx-twice: b\r\nContent-Length: 5\r\n\r\nhello");
        $request = json_decode(self::response($response)['body'], true);
        $this->assertSame(['POST', 'hello', 'a, b'], [$request['method'], $request['body'], $request['headers']['x-twice']]);
    }

    public function test_the_query_and_a_form_body_decode()
    {
        $body = 'name=W%C3%A9rner&likes=php&likes=gaz&note=a+b%2Bc';
        $response = self::response(self::exchange("POST /decoded?page=2&tag=x%20y HTTP/1.1\r\nHost: x\r\n"
            ."Content-Type: application/x-www-form-urlencoded\r\nContent-Length: ".strlen($body)."\r\n\r\n{$body}"));

        $this->assertSame(200, $response['status']);
        $this->assertSame([
            'query' => ['page' => ['2'], 'tag' => ['x y']],
            'form' => ['name' => ['Wérner'], 'likes' => ['php', 'gaz'], 'note' => ['a b+c']],
        ], json_decode($response['body'], true));
        // What a decoder refuses is the client's mistake, so a 400, and nothing for the log
        $this->assertSame([400, "Bad Request\n"], [self::get('/decoded')['status'], self::get('/decoded')['body']]);
        $this->assertSame(400, self::get('/decoded?q=%zz')['status']);
        $this->assertSame(400, self::get('/decoded?q=%ff')['status']);
        $this->assertSame(400, self::get('/decoded?%c3=1')['status']);
        $this->assertSame(400, self::get('/hello?name=%C0%AF')['status']);
        $this->assertSame(200, self::get('/hello?name=%E2%82%AC')['status']);
        self::loggedLine(self::$log, '/ GET \/hello\?name=%C0%AF 400 12 /');
        $this->assertStringNotContainsString('http::serve: GET /decoded', (string) file_get_contents(self::$log));
        $this->assertStringNotContainsString('http::serve: GET /hello', (string) file_get_contents(self::$log));
    }

    public function test_a_template_is_served_as_html()
    {
        $response = self::get('/hello?name=%3Cscript%3E');

        $this->assertSame(200, $response['status']);
        $this->assertSame('text/html; charset=utf-8', $response['headers']['content-type']);
        $this->assertSame("<p>Hello, &lt;script&gt;</p>\n", $response['body']);
    }

    public function test_a_chunked_body_is_put_back_together()
    {
        $response = self::exchange("PUT /echo HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\n\r\n5;ext=1\r\nhello\r\n7\r\n, world\r\n0\r\nTrailer: skipped\r\n\r\n");

        $this->assertSame('hello, world', json_decode(self::response($response)['body'], true)['body']);
    }

    public function test_a_client_that_expects_100_continue_hears_it_before_sending_the_body()
    {
        $socket = stream_socket_client('tcp://127.0.0.1:'.self::$port);
        $this->assertNotFalse($socket);
        stream_set_timeout($socket, 5);
        fwrite($socket, "POST /echo HTTP/1.1\r\nHost: x\r\nExpect: 100-continue\r\nContent-Length: 4\r\n\r\n");
        $this->assertSame("HTTP/1.1 100 Continue\r\n", fgets($socket));
        $this->assertSame("\r\n", fgets($socket));
        fwrite($socket, 'body');
        stream_socket_shutdown($socket, STREAM_SHUT_WR);
        $response = self::response((string) stream_get_contents($socket));
        fclose($socket);
        $this->assertSame([200, 'body'], [$response['status'], json_decode($response['body'], true)['body']]);
    }

    public function test_a_head_request_gets_the_headers_without_the_body()
    {
        $response = self::get('/', 'HEAD');

        $this->assertSame([200, '20', ''], [$response['status'], $response['headers']['content-length'], $response['body']]);
    }

    public function test_a_session_value_round_trips_through_its_cookie()
    {
        $first = self::exchange("GET /visits HTTP/1.1\r\nHost: x\r\n\r\n");
        $response = self::response($first);
        $this->assertSame(['visits' => 1], json_decode($response['body'], true));
        $this->assertMatchesRegularExpression('/^session=.+; Path=\/; HttpOnly; SameSite=Lax$/', $response['headers']['set-cookie']);

        $cookie = explode(';', $response['headers']['set-cookie'], 2)[0];
        $second = self::response(self::exchange("GET /visits HTTP/1.1\r\nHost: x\r\nCookie: {$cookie}\r\n\r\n"));
        $this->assertSame(['visits' => 2], json_decode($second['body'], true));
    }

    public function test_a_request_with_no_cookie_gets_an_empty_session_not_an_error()
    {
        $response = self::response(self::exchange("GET /visits HTTP/1.1\r\nHost: x\r\n\r\n"));

        $this->assertSame(200, $response['status']);
        $this->assertSame(['visits' => 1], json_decode($response['body'], true));
    }

    public function test_a_tampered_session_cookie_is_an_empty_session_not_a_crash()
    {
        $first = self::response(self::exchange("GET /visits HTTP/1.1\r\nHost: x\r\n\r\n"));
        $cookie = explode(';', $first['headers']['set-cookie'], 2)[0];
        $tampered = str_replace('session=', 'session=x', $cookie);

        $response = self::response(self::exchange("GET /visits HTTP/1.1\r\nHost: x\r\nCookie: {$tampered}\r\n\r\n"));

        $this->assertSame(200, $response['status']);
        $this->assertSame(['visits' => 1], json_decode($response['body'], true));
    }

    public function test_two_cookies_are_two_set_cookie_lines_not_comma_joined()
    {
        $response = self::exchange("GET /two-cookies HTTP/1.1\r\nHost: x\r\n\r\n");
        [$head] = explode("\r\n\r\n", $response, 2);
        $lines = array_values(array_filter(explode("\r\n", $head), fn ($line) => str_starts_with($line, 'Set-Cookie:')));

        $this->assertSame(['Set-Cookie: a=1; Path=/; HttpOnly; SameSite=Lax', 'Set-Cookie: b=2; Path=/; HttpOnly; SameSite=Lax'], $lines);
    }

    public function test_a_500_closes_the_connection_and_what_was_pipelined_behind_it_is_never_answered()
    {
        $response = self::exchange("GET /fail HTTP/1.1\r\nHost: x\r\n\r\nGET / HTTP/1.1\r\nHost: x\r\n\r\n");

        $this->assertSame(1, substr_count($response, 'HTTP/1.1 '));
        $this->assertSame([500, 'close'], [self::response($response)['status'], self::response($response)['headers']['connection']]);
    }

    public function test_a_handler_may_ask_to_close_the_connection_and_nothing_else()
    {
        $handler = '$r -> ({"headers" => {"Connection" => $r["path"] == "/close" ? "Close" : "keep-alive"}, "body" => "ok"})';
        [$server, $port] = self::startSmallServer(1, '{}', $handler);
        try {
            $request = "GET /close HTTP/1.1\r\nHost: x\r\n\r\nGET /close HTTP/1.1\r\nHost: x\r\n\r\n";
            $response = self::exchange($request, $port);
            $this->assertSame(1, substr_count($response, 'HTTP/1.1 '));
            $this->assertSame(1, substr_count($response, "\r\nConnection: close\r\n"));
            $this->assertSame([200, 'ok'], [self::response($response)['status'], self::response($response)['body']]);

            $response = self::response(self::exchange("GET /keep HTTP/1.1\r\nHost: x\r\n\r\n", $port));
            $this->assertSame([500, 'close'], [$response['status'], $response['headers']['connection']]);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    public function test_a_handler_that_fails_is_a_500_and_a_line_on_standard_error()
    {
        $this->assertSame([500, "Internal Server Error\n"], [self::get('/fail')['status'], self::get('/fail')['body']]);
        $this->assertSame(500, self::get('/bad-header')['status']);
        // url_decode() may be given the server's own text, so its failure is a bug, not a 400
        $this->assertSame(500, self::get('/url-decode')['status']);

        $log = (string) file_get_contents(self::$log);
        $this->assertStringContainsString('http::serve: GET /fail: the handler failed on purpose at tests/programs/web_server.gaz:', $log);
        $this->assertStringContainsString('http::serve: GET /bad-header: HTTP error: header X-Split has a line break or NUL byte in its value', $log);
        $this->assertStringContainsString('http::serve: GET /url-decode: bad percent-escape "%" at 3', $log);
        // The error's line stays, and the request has its access line as well
        self::loggedLine(self::$log, '/ GET \/fail 500 22 /');
    }

    public function test_each_request_answered_is_a_line_in_the_access_log()
    {
        $this->assertSame(404, self::get('/access-log?a=1&b=%20')['status']);
        $this->assertSame(404, self::get('/access-log-head', 'HEAD')['status']);

        // time, address, method, target as sent, status, body bytes, milliseconds
        $line = self::loggedLine(self::$log, '/ GET \/access-log\?/');
        $this->assertCount(1, $line);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ 127\.0\.0\.1 GET \/access-log\?a=1&b=%20 404 '.strlen("no page at /access-log\n").' \d+\.\d+ms$/', $line[0]);
        // A HEAD sends no body
        self::loggedLine(self::$log, '/^\S+ 127\.0\.0\.1 HEAD \/access-log-head 404 0 \d+\.\d+ms$/');
    }

    public function test_the_access_log_escapes_what_the_request_sent()
    {
        // A line break, a terminal's escape, a backslash and bytes that aren't ASCII, in a target refused for them
        $forged = "/forged\n2026-01-01T00:00:00Z 6.6.6.6 GET /fake 200 0 0.1ms\x1b[31m\\x0a\xff\xc3\xa9";
        $response = self::response(self::exchange('GET '.str_replace(' ', '_', $forged)." HTTP/1.1\r\nHost: x\r\n\r\n"));
        $this->assertSame(400, $response['status']);

        $line = self::loggedLine(self::$log, '/ GET \/forged/');
        $this->assertMatchesRegularExpression('/ GET \/forged\\\\x0A2026-01-01T00:00:00Z_6\.6\.6\.6_GET_\/fake_200_0_0\.1ms\\\\x1B\[31m\\\\x5Cx0a\\\\xFF\\\\xC3\\\\xA9 400 12 \d+\.\d+ms$/', $line[0]);
        $this->assertDoesNotMatchRegularExpression('/^2026-01-01T00:00:00Z/m', (string) file_get_contents(self::$log));

        // A long target is cut off at a whole escape, so a line stays short enough to be written whole
        $this->assertSame(404, self::get('/long-'.str_repeat('a', 2000))['status']);
        $line = self::loggedLine(self::$log, '/ GET \/long-a/');
        $this->assertMatchesRegularExpression('/ GET \/long-a{354}\\\\\.\.\. 404 \d+ /', $line[0]);
        $this->assertLessThan(512, strlen($line[0]) + 1);
    }

    public function test_the_access_log_has_a_line_for_each_request_on_a_kept_open_connection_and_each_refusal()
    {
        $socket = self::connect();
        fwrite($socket, "GET /kept-1 HTTP/1.1\r\nHost: x\r\n\r\nGET /kept-2 HTTP/1.1\r\nHost: x\r\n\r\n");
        self::readResponse($socket);
        self::readResponse($socket);
        fclose($socket);
        $this->assertCount(1, self::loggedLine(self::$log, '/ GET \/kept-1 404 /'));
        $this->assertCount(1, self::loggedLine(self::$log, '/ GET \/kept-2 404 /'));

        // Refused after the request line, with its status; before it, with "-" for what wasn't read
        $this->assertSame(413, self::response(self::exchange("POST /too-big HTTP/1.1\r\nHost: x\r\nContent-Length: 200000\r\n\r\n"))['status']);
        self::loggedLine(self::$log, '/ POST \/too-big 413 \d+ /');
        $this->assertSame(400, self::response(self::exchange("NONSENSE\r\n\r\n"))['status']);
        self::loggedLine(self::$log, '/^\S+ 127\.0\.0\.1 - - 400 12 /');
    }

    public function test_the_access_log_times_a_request_from_its_first_byte()
    {
        // A client that connects and waits before asking: the wait is no request's
        $socket = self::connect();
        usleep(600000);
        fwrite($socket, "GET /slow-to-ask HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        stream_get_contents($socket);
        fclose($socket);

        $line = self::loggedLine(self::$log, '/ GET \/slow-to-ask 404 /');
        $this->assertSame(1, preg_match('/ (\d+\.\d{3})ms$/', $line[0], $m));
        $this->assertLessThan(300, (float) $m[1], $line[0]);
    }

    public function test_a_closed_standard_error_ends_neither_a_worker_nor_the_master()
    {
        /*
         * Standard error a pipe whose reader goes away, as a log reader that stops or restarts does.
         * PHP ignores SIGPIPE and a program it starts inherits that, so perl puts the default back
         * first, as a shell or a process manager starts a server.
         */
        if (trim((string) shell_exec('command -v perl')) === '') {
            $this->markTestSkipped('needs perl to start the server with SIGPIPE at its default');
        }
        $command = ['perl', '-e', '$SIG{PIPE} = "DEFAULT"; exec @ARGV or die', self::binary(), '-f', 'tests/programs/web_server.gaz', '--', '0', '1'];
        $server = proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, self::ROOT);
        $this->assertNotFalse($server);
        try {
            $this->assertSame(1, preg_match('/^listening on (\d+)$/', trim((string) fgets($pipes[1])), $m), 'web_server.gaz should listen');
            $port = (int) $m[1];
            fclose($pipes[2]);
            // Each answer writes an access line, the 500 an error line too, into the closed pipe
            for ($i = 0; $i < 3; $i++) {
                $this->assertSame(200, self::response(self::exchange("GET / HTTP/1.1\r\nHost: x\r\n\r\n", $port))['status']);
                $this->assertSame(500, self::response(self::exchange("GET /fail HTTP/1.1\r\nHost: x\r\n\r\n", $port))['status']);
            }
            // The worker exits, and the master writes that it starts another into the closed pipe
            self::exchange("GET /exit HTTP/1.1\r\nHost: x\r\n\r\n", $port);
            $this->assertSame(200, self::response(self::exchange("GET / HTTP/1.1\r\nHost: x\r\n\r\n", $port))['status']);
            $this->assertTrue(proc_get_status($server)['running'], 'the master should still be running');
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    public function test_the_access_log_can_be_turned_off()
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'gazlang-server');
        [$server, $port] = self::startSmallServer(1, '{"access_log" => false}', '$r -> (starts_with($r["path"], "/fail") ? throw "failed" : {"body" => "ok"})', '', $log);
        try {
            $this->assertSame(200, self::response(self::exchange("GET /quiet HTTP/1.1\r\nHost: x\r\n\r\n", $port))['status']);
            $this->assertSame(500, self::response(self::exchange("GET /fail\x9b[2J\xc2\x85 HTTP/1.1\r\nHost: x\r\n\r\n", $port))['status']);
            // Only the 500's error line, written before its response, its path escaped as the access
            // log's is (0x9B and U+0085 are the C1 controls CSI and NEL)
            $this->assertMatchesRegularExpression("/\\Ahttp::serve: GET \\/fail\\\\x9B\\[2J\\\\xC2\\\\x85: failed at line 1\n(  .*\n)+\\z/", (string) file_get_contents($log));
        } finally {
            proc_terminate($server);
            proc_close($server);
            @unlink($log);
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function refused(): array
    {
        return [
            'not HTTP' => ["GARBAGE\r\n\r\n", 400],
            'another version' => ["GET / HTTP/2.0\r\n\r\n", 505],
            'no Host' => ["GET / HTTP/1.1\r\n\r\n", 400],
            'HTTP/1.0 needs no Host' => ["GET /nowhere HTTP/1.0\r\n\r\n", 404],
            'a target that is not a path' => ["GET http://x/ HTTP/1.1\r\nHost: x\r\n\r\n", 400],
            'a control character in the target' => ["GET /a\x01b HTTP/1.1\r\nHost: x\r\n\r\n", 400],
            'a bad method' => ["G(T / HTTP/1.1\r\nHost: x\r\n\r\n", 400],
            'space before the colon' => ["GET / HTTP/1.1\r\nHost : x\r\n\r\n", 400],
            'a folded header' => ["GET / HTTP/1.1\r\nHost: x\r\nX: a\r\n b\r\n\r\n", 400],
            'a line without a colon' => ["GET / HTTP/1.1\r\nHost: x\r\nnonsense\r\n\r\n", 400],
            'a bare line feed in a value' => ["GET / HTTP/1.1\r\nHost: x\nX-Evil: y\r\n\r\n", 400],
            'a bad Content-Length' => ["POST /echo HTTP/1.1\r\nHost: x\r\nContent-Length: 1e3\r\n\r\n", 400],
            'two Content-Lengths' => ["POST /echo HTTP/1.1\r\nHost: x\r\nContent-Length: 1\r\nContent-Length: 1\r\n\r\na", 400],
            'both lengths' => ["POST /echo HTTP/1.1\r\nHost: x\r\nContent-Length: 1\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n", 400],
            'another coding' => ["POST /echo HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: gzip\r\n\r\n", 501],
            'a body too large' => ["POST /echo HTTP/1.1\r\nHost: x\r\nContent-Length: 100001\r\n\r\n", 413],
            'a chunked body too large' => ["POST /echo HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\n\r\n186a1\r\n", 413],
            'a chunk that never ends' => ["POST /echo HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\n\r\n1\r\nA".str_repeat('a', 70000), 431],
            'a bad chunk size' => ["POST /echo HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\n\r\nzz\r\n", 400],
            'a head too large' => ["GET / HTTP/1.1\r\nHost: x\r\nX: ".str_repeat('a', 70000)."\r\n\r\n", 431],
            'another expectation' => ["POST /echo HTTP/1.1\r\nHost: x\r\nExpect: magic\r\nContent-Length: 1\r\n\r\n", 417],
        ];
    }

    #[DataProvider('refused')]
    public function test_a_request_that_is_not_well_formed_never_reaches_the_handler(string $request, int $status)
    {
        $this->assertSame($status, self::response(self::exchange($request))['status']);
    }

    public function test_a_request_cut_off_is_a_400_and_a_connection_that_sends_nothing_gets_nothing()
    {
        $socket = stream_socket_client('tcp://127.0.0.1:'.self::$port);
        $this->assertNotFalse($socket);
        fwrite($socket, "POST /echo HTTP/1.1\r\nHost: x\r\nContent-Length: 10\r\n\r\nabc");
        stream_socket_shutdown($socket, STREAM_SHUT_WR);
        $this->assertSame(400, self::response((string) stream_get_contents($socket))['status']);
        fclose($socket);

        $socket = stream_socket_client('tcp://127.0.0.1:'.self::$port);
        $this->assertNotFalse($socket);
        stream_socket_shutdown($socket, STREAM_SHUT_WR);
        $this->assertSame('', stream_get_contents($socket));
        fclose($socket);

        // And the workers carry on
        $this->assertSame(200, self::get('/')['status']);
    }

    public function test_a_worker_that_dies_is_started_again_and_stopping_the_master_stops_them_all()
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'gazlang-server');
        [$server, $port] = self::startServer(2, $log);
        try {
            // At once: a worker that dies within a second of starting stops the lot only if it never
            // took a connection, and this one died of a request
            $this->assertSame('', self::exchange("GET /exit HTTP/1.1\r\nHost: x\r\n\r\n", $port));
            for ($i = 0; $i < 6; $i++) {
                $this->assertSame(200, self::response(self::exchange("GET / HTTP/1.1\r\nHost: x\r\n\r\n", $port))['status']);
            }
            // The master looks every 50ms, so the other worker may have answered all of those first
            for ($wait = 0; ! str_contains((string) file_get_contents($log), 'starting another') && $wait < 100; $wait++) {
                usleep(20000);
            }
            $this->assertMatchesRegularExpression('/^gaz: worker [12] exited with code 3; starting another$/m', (string) file_get_contents($log));

            proc_terminate($server);
            for ($wait = 0; ($status = proc_get_status($server))['running'] && $wait < 100; $wait++) {
                usleep(50000);
            }
            $this->assertSame([false, true, SIGTERM], [$status['running'], $status['signaled'], $status['termsig']]);
            $this->assertFalse(self::listens($port), 'a worker still listens');
        } finally {
            proc_terminate($server);
            proc_close($server);
            @unlink($log);
        }
    }

    /**
     * Whether something accepts a connection on a port; the refusal's warning is expected, so it
     * is caught here rather than hidden with @, which Pest reports as the test's own
     */
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

    /**
     * The pids of a proc_open()ed process's direct children, from the process table
     *
     * @return list<int>
     */
    private static function childPids(int $pid): array
    {
        exec("pgrep -P {$pid}", $lines);

        return array_map('intval', $lines);
    }

    public function test_a_worker_recycles_after_max_requests_without_dropping_a_request_or_shrinking_the_pool()
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'gazlang-server');
        [$server, $port] = self::startServer(2, $log, 3);
        try {
            $before = [];
            for ($wait = 0; count($before) < 2 && $wait < 100; $wait++) {
                $before = self::childPids((int) proc_get_status($server)['pid']);
                usleep(20000);
            }
            $this->assertCount(2, $before, 'both workers should have started');

            // More requests than 2 workers * 3 max_requests, so at least one worker recycles
            for ($i = 0; $i < 10; $i++) {
                $this->assertSame(200, self::response(self::exchange("GET / HTTP/1.1\r\nHost: x\r\n\r\n", $port))['status']);
            }

            $after = [];
            for ($wait = 0; $wait < 100; $wait++) {
                $after = self::childPids((int) proc_get_status($server)['pid']);
                if (count($after) === 2 && $after !== $before) {
                    break;
                }
                usleep(20000);
            }
            $this->assertCount(2, $after, 'the pool should stay 2 workers wide');
            $this->assertNotEquals($before, $after, 'at least one worker should have been replaced');

            // The requests after a recycle still succeed
            $this->assertSame(200, self::response(self::exchange("GET / HTTP/1.1\r\nHost: x\r\n\r\n", $port))['status']);

            $errors = (string) file_get_contents($log);
            $this->assertMatchesRegularExpression('/^gaz: worker [12] recycled; starting another$/m', $errors);
            $this->assertDoesNotMatchRegularExpression('/exited with code|died of signal/', $errors);
        } finally {
            proc_terminate($server);
            proc_close($server);
            @unlink($log);
        }
    }

    public function test_a_retiring_worker_serves_until_its_replacement_is_ready()
    {
        /*
         * Each worker takes $startup seconds after workers() before it serves, as an app that
         * connects to its database and builds itself does, and retires after every request. A
         * retiring worker that left at once would leave the pool empty for that long; one that
         * serves on until its replacement waits for connections leaves no gap.
         */
        $startup = 1.5;
        [$server, $port] = self::startSmallServer(2, '{"max_requests" => 1}', startup: "sleep({$startup});");
        try {
            // The first request waits for the first workers to start
            $this->assertSame(200, self::response(self::exchange("GET / HTTP/1.1\r\nHost: x\r\n\r\n", $port))['status']);
            $slowest = 0.0;
            $until = microtime(true) + 2.5 * $startup;
            for ($count = 0; microtime(true) < $until; $count++) {
                $start = microtime(true);
                $this->assertSame(200, self::response(self::exchange("GET / HTTP/1.1\r\nHost: x\r\n\r\n", $port))['status']);
                $slowest = max($slowest, microtime(true) - $start);
                usleep(20000);
            }
            $this->assertLessThan($startup / 2, $slowest, "the slowest of {$count} requests");
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    /** A handler that takes the seconds its path names ("/0.25"), then answers "ok" */
    private const SLEEPY = '$r -> { sleep(to_float(slice($r["path"], 1), 0)); return {"body" => "ok"}; }';

    /** What a handler past its deadline gets, exactly: no Date, since it was made before it was sent */
    private const TIMED_OUT = "HTTP/1.1 503 Service Unavailable\r\nConnection: close\r\nContent-Type: text/plain\r\nContent-Length: 20\r\n\r\n";

    /**
     * The log once its text matches a pattern, or fails after 5 seconds
     */
    private static function logOnceItHas(string $log, string $pattern): string
    {
        for ($wait = 0; ! preg_match($pattern, $text = (string) file_get_contents($log)) && $wait < 100; $wait++) {
            usleep(50000);
        }
        if (! preg_match($pattern, $text)) {
            throw new \RuntimeException("Nothing matching {$pattern} in the log:\n{$text}");
        }

        return $text;
    }

    public function test_a_handler_past_its_deadline_is_answered_503_and_its_worker_replaced()
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'gazlang-server');
        [$server, $port] = self::startSmallServer(1, '{"handler_timeout" => 0.3}', self::SLEEPY, '', $log);
        try {
            $start = microtime(true);
            $response = self::exchange("GET /5 HTTP/1.1\r\nHost: x\r\n\r\n", $port);
            $this->assertSame(self::TIMED_OUT."Service Unavailable\n", $response);
            $this->assertLessThan(2, microtime(true) - $start);
            // A HEAD's 503 has the headers alone
            $this->assertSame(self::TIMED_OUT, self::exchange("HEAD /5 HTTP/1.1\r\nHost: x\r\n\r\n", $port));

            $text = self::logOnceItHas($log, '/(timed out.*\n.*){2}/s');
            $this->assertSame(2, preg_match_all('/^gaz: worker 1 timed out on a request; starting another$/m', $text));
            $this->assertDoesNotMatchRegularExpression('/died of signal/', $text);
            // The access log has each, with the deadline as its time taken
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ 127\.0\.0\.1 GET \/5 503 20 300\.000ms$/m', $text);
            $this->assertMatchesRegularExpression('/^\S+ 127\.0\.0\.1 HEAD \/5 503 0 300\.000ms$/m', $text);

            // The next request is a new worker's
            $next = self::response(self::exchange("GET /0 HTTP/1.1\r\nHost: x\r\n\r\n", $port));
            $this->assertSame([200, 'ok'], [$next['status'], $next['body']]);
        } finally {
            proc_terminate($server);
            proc_close($server);
            @unlink($log);
        }
    }

    public function test_a_deadline_is_cleared_once_the_handler_returns_or_raises()
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'gazlang-server');
        $handler = '$r -> $r["path"] == "/fail" ? throw "failed" : ('.self::SLEEPY.')($r)';
        [$server, $port] = self::startSmallServer(1, '{"handler_timeout" => 0.4}', $handler, '', $log);
        try {
            // Under the deadline, then idle on the kept-open connection past it: no timer is left
            $socket = self::connect($port);
            fwrite($socket, "GET /0.2 HTTP/1.1\r\nHost: x\r\n\r\n");
            $this->assertSame(200, self::response(self::readResponse($socket))['status']);
            usleep(600000);
            fwrite($socket, "GET /0 HTTP/1.1\r\nHost: x\r\n\r\n");
            $this->assertSame(200, self::response(self::readResponse($socket))['status']);
            fclose($socket);

            // A handler that raises: a 500, then the worker waits for a connection past the deadline
            $this->assertSame(500, self::response(self::exchange("GET /fail HTTP/1.1\r\nHost: x\r\n\r\n", $port))['status']);
            usleep(600000);
            $this->assertSame(200, self::response(self::exchange("GET /0 HTTP/1.1\r\nHost: x\r\n\r\n", $port))['status']);
            $this->assertStringNotContainsString('timed out', (string) file_get_contents($log));
        } finally {
            proc_terminate($server);
            proc_close($server);
            @unlink($log);
        }
    }

    /**
     * null and the longest handler_timeout are accepted and serve a handler slower than the test's
     * own deadlines. That null sets no timer at all (set_deadline() returns before worker_deadline())
     * can't be seen from outside, nor told from the 10 second default in a test this short; the
     * longest is the one whose deadline's log stamp once raised and ended the worker on every request.
     */
    public function test_a_handler_timeout_of_null_or_the_longest_serves_a_slow_handler()
    {
        foreach (['null', '100000000'] as $limit) {
            [$server, $port] = self::startSmallServer(1, "{\"handler_timeout\" => {$limit}}", self::SLEEPY);
            try {
                $this->assertSame(200, self::response(self::exchange("GET /0.5 HTTP/1.1\r\nHost: x\r\n\r\n", $port))['status'], $limit);
            } finally {
                proc_terminate($server);
                proc_close($server);
            }
        }
    }

    public function test_a_signal_ignored_when_the_server_started_stays_ignored()
    {
        // As nohup leaves SIGHUP: the terminal closing must not stop the server
        $server = proc_open(['sh', '-c', 'trap "" HUP; exec "$0" -f tests/programs/web_server.gaz -- 0 2', self::binary()], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $pipes, self::ROOT);
        $this->assertNotFalse($server);
        try {
            $port = (int) substr(trim((string) fgets($pipes[1])), strlen('listening on '));
            proc_terminate($server, SIGHUP);
            usleep(300000);
            $this->assertTrue(proc_get_status($server)['running']);
            $this->assertSame(200, self::response(self::exchange("GET / HTTP/1.1\r\nHost: x\r\n\r\n", $port))['status']);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    public function test_a_request_that_takes_too_long_to_arrive_is_a_408()
    {
        // Each byte comes well within the 2 second read timeout, but the whole never does
        $socket = stream_socket_client('tcp://127.0.0.1:'.self::$port);
        $this->assertNotFalse($socket);
        stream_set_blocking($socket, false);
        $start = microtime(true);
        $response = '';
        foreach (str_split("GET / HTTP/1.1\r\nHost: x\r\nX-Slow: ".str_repeat('a', 20)) as $byte) {
            @fwrite($socket, $byte);
            usleep(250000);
            $response .= (string) fread($socket, 8192);
            if ($response !== '') {
                break;
            }
        }
        stream_set_blocking($socket, true);
        stream_set_timeout($socket, 5);
        $response .= (string) stream_get_contents($socket);
        fclose($socket);

        $this->assertSame(408, self::response($response)['status']);
        $this->assertLessThan(4, microtime(true) - $start);
    }

    public function test_stopping_lets_the_request_in_hand_finish()
    {
        [$server, $port] = self::startServer(2, '/dev/null');
        try {
            $socket = stream_socket_client("tcp://127.0.0.1:{$port}");
            $this->assertNotFalse($socket);
            stream_set_timeout($socket, 5);
            fwrite($socket, "GET / HTTP/1.1\r\n");
            usleep(200000);
            proc_terminate($server);
            usleep(300000);
            // The busy worker waits for the rest of its request, and answers it
            fwrite($socket, "Host: x\r\n\r\n");
            [$seconds, $rest] = self::untilClosed($socket);
            $response = self::response($rest);
            fclose($socket);
            $this->assertSame(200, $response['status']);
            /*
             * Then the connection closes, though the client didn't ask, without waiting for the
             * idle timeout; the response can't say Connection: close, since nothing tells a worker
             * it was asked to stop until it next waits
             */
            $this->assertLessThan(2, $seconds);
            $this->assertArrayNotHasKey('connection', $response['headers']);

            for ($wait = 0; ($status = proc_get_status($server))['running'] && $wait < 100; $wait++) {
                usleep(50000);
            }
            $this->assertSame([false, true, SIGTERM], [$status['running'], $status['signaled'], $status['termsig']]);
            $this->assertFalse(self::listens($port), 'a worker still listens');
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    public function test_a_connection_carries_requests_until_it_is_idle_too_long_and_then_closes_without_a_word()
    {
        [$server, $port] = self::startSmallServer(1, '{"idle_timeout" => 1, "request_timeout" => 0.5}');
        try {
            $socket = self::connect($port);
            for ($i = 0; $i < 3; $i++) {
                // Longer than request_timeout, which starts again with each request
                if ($i > 0) {
                    usleep(600000);
                }
                fwrite($socket, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");
                $response = self::response(self::readResponse($socket));
                $this->assertSame([200, 'ok'], [$response['status'], $response['body']]);
                $this->assertArrayNotHasKey('connection', $response['headers']);
            }
            [$seconds, $rest] = self::untilClosed($socket);
            fclose($socket);
            $this->assertSame('', $rest);
            $this->assertGreaterThan(0.8, $seconds);
            $this->assertLessThan(3, $seconds);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    public function test_a_worker_idle_on_a_kept_open_connection_stops_within_about_a_second()
    {
        [$server, $port] = self::startSmallServer(1, '{"idle_timeout" => 30}');
        try {
            $socket = self::connect($port);
            fwrite($socket, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");
            $this->assertSame(200, self::response(self::readResponse($socket))['status']);
            usleep(200000);
            proc_terminate($server);
            [$seconds, $rest] = self::untilClosed($socket);
            fclose($socket);
            $this->assertSame('', $rest);
            $this->assertLessThan(2, $seconds);

            $status = self::ended($server);
            $this->assertSame([false, true, SIGTERM], [$status['running'], $status['signaled'], $status['termsig']]);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    /**
     * A connection that has had its response and is kept open, on a server
     *
     * @return resource
     */
    private static function keptOpen(int $port)
    {
        $socket = self::connect($port);
        fwrite($socket, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");
        $response = self::response(self::readResponse($socket));
        if ($response['status'] !== 200 || isset($response['headers']['connection'])) {
            throw new \RuntimeException('The connection was not kept open');
        }

        return $socket;
    }

    public function test_an_idle_connection_gives_its_worker_up_to_a_client_that_would_wait()
    {
        // One worker, and an idle timeout far longer than anyone waits here
        [$server, $port] = self::startSmallServer(1, '{"idle_timeout" => 30}');
        try {
            $idle = self::keptOpen($port);
            usleep(100000);

            $start = microtime(true);
            $response = self::response(self::exchange("GET / HTTP/1.1\r\nHost: x\r\n\r\n", $port));
            $this->assertSame(200, $response['status']);
            $this->assertLessThan(0.5, microtime(true) - $start);

            // The idle connection was closed for it, without a word
            [$seconds, $rest] = self::untilClosed($idle);
            fclose($idle);
            $this->assertSame('', $rest);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    public function test_an_idle_connection_stays_open_when_a_free_worker_takes_the_new_client()
    {
        [$server, $port] = self::startSmallServer(2, '{"idle_timeout" => 30}');
        try {
            /*
             * The free worker has YIELD_GRACE (10ms) to accept the new client before the idle one
             * gives way: a busy machine can miss that now and then, which is harmless in a server
             * but not in a test, so one success in three tries is what is asked
             */
            $kept = false;
            for ($try = 0; $try < 3 && ! $kept; $try++) {
                $idle = self::keptOpen($port);
                usleep(100000);
                $this->assertSame(200, self::response(self::exchange("GET / HTTP/1.1\r\nHost: x\r\n\r\n", $port))['status']);
                usleep(100000);
                fwrite($idle, "GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
                $kept = str_starts_with((string) stream_get_contents($idle), 'HTTP/1.1 200 OK');
                fclose($idle);
            }
            $this->assertTrue($kept, 'the idle connection gave way though a free worker took the new client');
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    public function test_new_clients_taken_by_another_worker_do_not_extend_an_idle_wait()
    {
        [$server, $port] = self::startSmallServer(2, '{"idle_timeout" => 1}');
        try {
            $idle = self::keptOpen($port);
            $start = microtime(true);
            // A new client every 0.3s for 2.4s: each wakes the idle worker, which must not start over
            for ($i = 0; $i < 8; $i++) {
                usleep(300000);
                self::exchange("GET / HTTP/1.1\r\nHost: x\r\n\r\n", $port);
            }
            stream_set_blocking($idle, false);
            $closed = fread($idle, 1) === '' && feof($idle);
            fclose($idle);
            $this->assertTrue($closed, 'the idle connection was still open after '.round(microtime(true) - $start, 1).'s');
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    /**
     * Whether the server has closed a connection its client isn't using, as a browser looks before
     * sending on one it kept
     *
     * @param  resource  $socket
     */
    private static function closedWhileIdle($socket): bool
    {
        stream_set_blocking($socket, false);
        $ended = fread($socket, 1) === '' && feof($socket);
        stream_set_blocking($socket, true);

        return $ended;
    }

    public function test_once_a_worker_has_given_way_it_hands_connections_over_at_their_responses()
    {
        [$server, $port] = self::startSmallServer(1, '{"idle_timeout" => 30}');
        try {
            /*
             * Two clients take turns on one worker, each asking again only once the other has its
             * answer, so each is idle while the other waits. Closing an idle connection races with
             * a request its client sends at that moment, which then gets no response, while a
             * response that says Connection: close races with nothing. So the worker cuts one idle
             * connection, to let the first waiting client in, and from then on gives each up at
             * a response.
             */
            $clients = [null, null];
            $cut = 0;
            for ($round = 0; $round < 20; $round++) {
                $mine = $round % 2;
                if ($clients[$mine] !== null && self::closedWhileIdle($clients[$mine])) {
                    $cut++;
                    fclose($clients[$mine]);
                    $clients[$mine] = null;
                }
                $clients[$mine] ??= self::connect($port);
                fwrite($clients[$mine], "GET / HTTP/1.1\r\nHost: x\r\n\r\n");
                $response = self::response(self::readResponse($clients[$mine]));
                $this->assertSame(200, $response['status']);
                if (($response['headers']['connection'] ?? '') === 'close') {
                    fclose($clients[$mine]);
                    $clients[$mine] = null;
                }
            }
            $this->assertSame(1, $cut, 'idle connections cut in 20 turns');
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    public function test_a_busy_connection_gives_way_to_a_waiting_client_after_its_turn()
    {
        // One worker, each request taking 30ms: a hundred to a connection would be three seconds
        [$server, $port] = self::startSmallServer(1, '{"idle_timeout" => 30}', '$r -> { sleep(0.03); return {"body" => "ok"}; }');
        try {
            $busy = self::keptOpen($port);
            $waiting = self::connect($port);
            fwrite($waiting, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");
            $start = microtime(true);
            // The busy client asks again as soon as it has its answer, until it is told it closes
            $closed = false;
            for ($asked = 0; ! $closed && $asked < 100; $asked++) {
                fwrite($busy, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");
                $response = self::response(self::readResponse($busy));
                $this->assertSame(200, $response['status']);
                $closed = ($response['headers']['connection'] ?? '') === 'close';
            }
            fclose($busy);
            $this->assertTrue($closed, 'the busy connection was never handed over');
            $this->assertSame(200, self::response(self::readResponse($waiting))['status']);
            fclose($waiting);
            // A TURN (50ms) and a request or two, with room for a busy machine
            $this->assertLessThan(1, microtime(true) - $start, "the waiting client was answered after the busy one had {$asked}");
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    public function test_max_requests_counts_the_requests_on_one_connection()
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'gazlang-server');
        [$server, $port] = self::startServer(1, $log, 3);
        try {
            $socket = self::connect($port);
            $connections = [];
            for ($i = 0; $i < 3; $i++) {
                fwrite($socket, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");
                $response = self::response(self::readResponse($socket));
                $this->assertSame(200, $response['status']);
                $connections[] = $response['headers']['connection'] ?? null;
            }
            // The worker's last request says so, and the connection closes before it recycles
            $this->assertSame([null, null, 'close'], $connections);
            $this->assertSame('', (string) stream_get_contents($socket));
            fclose($socket);

            for ($wait = 0; ! str_contains((string) file_get_contents($log), 'recycled') && $wait < 100; $wait++) {
                usleep(20000);
            }
            $this->assertMatchesRegularExpression('/^gaz: worker 1 recycled; starting another$/m', (string) file_get_contents($log));
            $this->assertSame(200, self::response(self::exchange("GET / HTTP/1.1\r\nHost: x\r\n\r\n", $port))['status']);
        } finally {
            proc_terminate($server);
            proc_close($server);
            @unlink($log);
        }
    }

    public function test_a_worker_waiting_on_an_idle_connection_stops_within_about_a_second()
    {
        // The worker waits on a connection nothing is sent on, for longer than the master's grace
        $code = '$l = socket_listen("127.0.0.1", 0); workers(1); $c = socket_open("127.0.0.1", socket_port($l)); '
            .'$s = socket_accept($l); print("waiting\n"); flush_output(); echo socket_wait([$s], 60); echo "stopped";';
        $server = proc_open([self::binary(), '-e', $code], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $pipes, self::ROOT);
        $this->assertNotFalse($server);
        try {
            $this->assertSame("waiting\n", fgets($pipes[1]));
            usleep(200000);
            $start = microtime(true);
            proc_terminate($server);
            $out = (string) stream_get_contents($pipes[1]);
            $this->assertLessThan(2.5, microtime(true) - $start);
            $this->assertSame("null\nstopped\n", $out);
        } finally {
            proc_terminate($server, SIGKILL);
            proc_close($server);
        }
    }

    public function test_workers_ends_when_every_worker_has_ended()
    {
        [$out, $err, $code] = self::gazlang([], 'print("before\n"); echo workers(3);');
        $lines = explode("\n", rtrim($out));

        // What was printed before is printed once, not once a worker
        $this->assertSame('before', array_shift($lines));
        sort($lines);
        $this->assertSame([['1', '2', '3'], '', 0], [$lines, $err, $code]);
    }

    public function test_a_worker_that_fails_at_once_stops_the_master_with_its_code()
    {
        [$out, $err, $code] = self::gazlang([], '$l = socket_listen("127.0.0.1", 0); if (workers(2) == 2) { exit(4); } socket_accept($l);');

        $this->assertSame(4, $code);
        $this->assertSame("gaz: worker 2 exited with code 4 within a second of starting; stopping\n", $err);

        [$out, $err, $code] = self::gazlang([], 'workers(1); workers(1);');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Error: workers() in a worker: only the first process can start them', $err);
        $this->assertStringContainsString('gaz: worker 1 exited with code 1 within a second of starting; stopping', $err);
    }

    public function test_each_worker_draws_its_own_random_numbers()
    {
        [$out, , $code] = self::gazlang([], 'rand_seed(1); workers(4); echo rand_int(0, 1000000000);');

        $this->assertSame(0, $code);
        $this->assertCount(4, array_unique(explode("\n", rtrim($out))));
    }

    public function test_a_listener_only_accepts()
    {
        $this->assertSame(
            "socket (listening) true\nsocket_read() on a listening socket: socket_accept() a connection\n"
            ."socket_write() on a listening socket: socket_accept() a connection\nsocket (closed)\nsocket_accept() on a closed socket\nsocket_port() on a closed socket\n",
            $this->executeCode('$l = socket_listen("127.0.0.1", 0); echo "$l " .. (socket_port($l) > 0);'
                .' try { socket_read($l); } catch (Error $e) { echo $e.message; }'
                .' try { socket_write($l, "x"); } catch (Error $e) { echo $e.message; }'
                .' socket_close($l); echo $l;'
                .' try { socket_accept($l); } catch (Error $e) { echo $e.message; }'
                .' try { socket_port($l); } catch (Error $e) { echo $e.message; }')
        );
    }

    public function test_a_port_in_use_cannot_be_listened_on()
    {
        $this->assertStringStartsWith('Cannot listen on 127.0.0.1 port '.self::$port.': ', rtrim(self::succeed([], 'try { socket_listen("127.0.0.1", '.self::$port.'); } catch (Error $e) { echo $e.message; }')));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function misused(): array
    {
        return [
            'no host' => ['socket_listen("", 80);', 'socket_listen() expects a host name or address'],
            'a port too high' => ['socket_listen("127.0.0.1", 65536);', 'socket_listen() expects a port from 0 to 65535, got 65536'],
            'no backlog' => ['socket_listen("127.0.0.1", 0, 0);', 'socket_listen() expects a backlog of 1 or more, got 0'],
            'accepting on a connection' => ['socket_accept("x");', 'socket_accept() expects socket, got string'],
            'no timeout' => ['socket_accept(socket_listen("127.0.0.1", 0), 0);', 'socket_accept() expects a timeout above 0 seconds'],
            'no workers' => ['workers(0);', 'workers() expects 1 to 1024 workers, got 0'],
            'too many workers' => ['workers(1025);', 'workers() expects 1 to 1024 workers, got 1025'],
            'options not a map' => ['import "std/http.gaz"; http::serve(null, null, []);', 'http::serve() expects a map of options, got list'],
            'an option there is not' => ['import "std/http.gaz"; http::serve(null, null, {"port" => 1});', 'http::serve() has no option "port": only timeout, request_timeout, max_body'],
            'no idle timeout' => ['import "std/http.gaz"; http::serve(null, null, {"idle_timeout" => 0});', 'http::serve() expects an idle_timeout above 0 seconds, got 0'],
            'an idle timeout not a number' => ['import "std/http.gaz"; http::serve(null, null, {"idle_timeout" => "5"});', 'http::serve() expects an idle_timeout above 0 seconds, got "5"'],
            'no requests on a connection' => ['import "std/http.gaz"; http::serve(null, null, {"requests_per_connection" => 0});', 'http::serve() expects a requests_per_connection of 1 or more, got 0'],
            'a fraction of a request' => ['import "std/http.gaz"; http::serve(null, null, {"requests_per_connection" => 1.5});', 'http::serve() expects a requests_per_connection of 1 or more, got 1.5'],
            'no handler timeout' => ['import "std/http.gaz"; http::serve(null, null, {"handler_timeout" => 0});', 'http::serve() expects a handler_timeout above 0 seconds and at most 100000000, or null, got 0'],
            'a negative handler timeout' => ['import "std/http.gaz"; http::serve(null, null, {"handler_timeout" => -1.5});', 'http::serve() expects a handler_timeout above 0 seconds and at most 100000000, or null, got -1.5'],
            'a handler timeout past the timer\'s ceiling' => ['import "std/http.gaz"; http::serve(null, null, {"handler_timeout" => 1e300});', 'http::serve() expects a handler_timeout above 0 seconds and at most 100000000, or null, got 1.0E+300'],
            'a handler timeout not a number' => ['import "std/http.gaz"; http::serve(null, null, {"handler_timeout" => "10"});', 'http::serve() expects a handler_timeout above 0 seconds and at most 100000000, or null, got "10"'],
            'an access log not a bool' => ['import "std/http.gaz"; http::serve(null, null, {"access_log" => "off"});', 'http::serve() expects an access_log of true or false, got "off"'],
        ];
    }

    #[DataProvider('misused')]
    public function test_what_is_misused_is_an_error(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode($code);
    }
}
