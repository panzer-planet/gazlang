<?php

namespace GazLang\Tests;

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
    private static function startServer(int $workers, string $log): array
    {
        $server = proc_open([self::binary(), '-f', 'tests/programs/web_server.gaz', '--', '0', (string) $workers], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', $log, 'w']], $pipes, self::ROOT);
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
     * Send bytes and give everything that comes back until the server closes
     */
    private static function exchange(string $request, ?int $port = null): string
    {
        $socket = stream_socket_client('tcp://127.0.0.1:'.($port ?? self::$port), $errno, $error, 5);
        if ($socket === false) {
            throw new \RuntimeException("Cannot connect: {$error}");
        }
        stream_set_timeout($socket, 5);
        fwrite($socket, $request);
        $response = (string) stream_get_contents($socket);
        fclose($socket);

        return $response;
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
        $this->assertSame('close', $response['headers']['connection']);
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
        $this->assertSame(['method' => 'GET', 'path' => '/echo', 'query' => 'a=1&b=%20', 'headers' => ['host' => 'localhost'], 'body' => ''], $request);

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
        // A request that isn't a form is the handler's error, so a 500
        $this->assertSame(500, self::get('/decoded')['status']);
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
        $response = self::response((string) stream_get_contents($socket));
        fclose($socket);
        $this->assertSame([200, 'body'], [$response['status'], json_decode($response['body'], true)['body']]);
    }

    public function test_a_head_request_gets_the_headers_without_the_body()
    {
        $response = self::get('/', 'HEAD');

        $this->assertSame([200, '20', ''], [$response['status'], $response['headers']['content-length'], $response['body']]);
    }

    public function test_a_handler_that_fails_is_a_500_and_a_line_on_standard_error()
    {
        $this->assertSame([500, "Internal Server Error\n"], [self::get('/fail')['status'], self::get('/fail')['body']]);
        $this->assertSame(500, self::get('/bad-header')['status']);

        $log = (string) file_get_contents(self::$log);
        $this->assertStringContainsString('http::serve: GET /fail: the handler failed on purpose at tests/programs/web_server.gaz:', $log);
        $this->assertStringContainsString('http::serve: GET /bad-header: HTTP error: header X-Split has a line break or NUL byte in its value', $log);
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

    /**
     * @dataProvider refused
     */
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
            $this->assertMatchesRegularExpression('/^gazlang: worker [12] exited with code 3; starting another$/m', (string) file_get_contents($log));

            proc_terminate($server);
            for ($wait = 0; ($status = proc_get_status($server))['running'] && $wait < 100; $wait++) {
                usleep(50000);
            }
            $this->assertSame([false, true, SIGTERM], [$status['running'], $status['signaled'], $status['termsig']]);
            $this->assertFalse(@stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 1), 'a worker still listens');
        } finally {
            proc_terminate($server);
            proc_close($server);
            @unlink($log);
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
            $response = self::response((string) stream_get_contents($socket));
            fclose($socket);
            $this->assertSame(200, $response['status']);

            for ($wait = 0; ($status = proc_get_status($server))['running'] && $wait < 100; $wait++) {
                usleep(50000);
            }
            $this->assertSame([false, true, SIGTERM], [$status['running'], $status['signaled'], $status['termsig']]);
            $this->assertFalse(@stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 1), 'a worker still listens');
        } finally {
            proc_terminate($server);
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
        $this->assertSame("gazlang: worker 2 exited with code 4 within a second of starting; stopping\n", $err);

        [$out, $err, $code] = self::gazlang([], 'workers(1); workers(1);');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Error: workers() in a worker: only the first process can start them', $err);
        $this->assertStringContainsString('gazlang: worker 1 exited with code 1 within a second of starting; stopping', $err);
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
            'options not a map' => ['include "'.self::ROOT.'/lib/http.gaz"; http::serve(null, null, []);', 'http::serve() expects a map of options, got list'],
            'an option there is not' => ['include "'.self::ROOT.'/lib/http.gaz"; http::serve(null, null, {"port" => 1});', 'http::serve() has no option "port": only timeout, request_timeout, max_body'],
        ];
    }

    /**
     * @dataProvider misused
     */
    public function test_what_is_misused_is_an_error(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode($code);
    }
}
