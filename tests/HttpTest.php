<?php

namespace GazLang\Tests;

/**
 * lib/http.gaz and the socket builtins, against tests/fixtures/http_server.php run twice: over
 * plain TCP, and over TLS with tests/fixtures/tls/server.pem, a certificate for localhost made
 * for these tests (cert.pem is it without its key, which SSL_CERT_FILE makes the one trusted)
 *
 * The ports are whatever is free, so what these print can't be recorded: they run through
 * succeed() rather than executeCode(), and check the response by its parts. What is refused
 * before anything is sent runs through executeCode(), and is recorded.
 */
class HttpTest extends GazLangTestCase
{
    /** @var list<resource> */
    private static array $servers = [];

    /** The plain server's http://127.0.0.1:PORT, and the TLS one's port */
    private static string $url = '';

    private static string $tlsPort = '';

    public static function setUpBeforeClass(): void
    {
        self::$url = 'http://'.self::startServer([]);
        $address = self::startServer(['tests/fixtures/tls/server.pem']);
        self::$tlsPort = substr($address, strrpos($address, ':') + 1);
    }

    /**
     * Start the server on a free port, and give the address it printed
     *
     * @param  list<string>  $args  After the address
     */
    private static function startServer(array $args): string
    {
        $server = proc_open([PHP_BINARY, 'tests/fixtures/http_server.php', '127.0.0.1:0', ...$args], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $pipes, self::ROOT);
        if ($server === false) {
            throw new \RuntimeException('Cannot start tests/fixtures/http_server.php');
        }
        self::$servers[] = $server;
        $address = trim((string) fgets($pipes[1]));
        if ($address === '') {
            throw new \RuntimeException('tests/fixtures/http_server.php never listened');
        }

        return $address;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }
        self::$servers = [];
    }

    /**
     * What GazLang code printed, with lib/http.gaz and lib/json.gaz included and $url the plain
     * server's URL
     *
     * @param  array<string, string>  $env
     */
    private function gaz(string $code, array $env = []): string
    {
        $root = self::ROOT;

        return self::succeed([], "include \"{$root}/lib/http.gaz\"; include \"{$root}/lib/json.gaz\"; \$url = ".self::quote(self::$url)."; {$code}", $env);
    }

    /**
     * What a call to lib/http.gaz returns, through JSON
     *
     * @param  array<string, string>  $env
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function call(string $call, array $env = []): array
    {
        return json_decode($this->gaz("print(json_encode({$call}));", $env), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The request the server saw, from the body it echoed
     *
     * @param  array{status: int, headers: array<string, string>, body: string}  $response
     * @return array{method: string, uri: string, headers: array<string, string>, body: string}
     */
    private static function request(array $response): array
    {
        return json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The message of the error the code raised
     *
     * @param  array<string, string>  $env
     */
    private function failure(string $code, array $env = []): string
    {
        return rtrim($this->gaz("try { {$code}; echo \"no error\"; } catch (Error \$e) { echo \$e.message; }", $env), "\n");
    }

    public function test_get_sends_the_headers_and_gives_the_status_headers_and_body()
    {
        $response = $this->call('http_get("{$url}/path?q=[1]&r={2}#part", {"X-Test" => "yes", "X-Number" => 5, "X-Empty" => ""})');

        $this->assertSame(200, $response['status']);
        $this->assertSame('application/json', $response['headers']['content-type']);
        $request = self::request($response);
        $this->assertSame(['GET', '/path?q=[1]&r={2}', ''], [$request['method'], $request['uri'], $request['body']]);
        $port = substr(self::$url, strrpos(self::$url, ':') + 1);
        $this->assertSame(
            ['host' => "127.0.0.1:{$port}", 'x-test' => 'yes', 'x-number' => '5', 'x-empty' => '', 'user-agent' => 'gazlang', 'accept' => '*/*', 'connection' => 'close'],
            $request['headers']
        );
    }

    public function test_given_headers_replace_the_defaults()
    {
        $request = self::request($this->call('http_get("{$url}/", {"user-agent" => "mine", "Accept" => "text/plain"})'));
        $this->assertSame(['mine', 'text/plain'], [$request['headers']['user-agent'], $request['headers']['accept']]);
    }

    public function test_post_sends_the_body_as_it_is_whatever_its_size_and_bytes()
    {
        $request = self::request($this->call('http_post("{$url}/submit", "@tests/fixtures/read_me.txt", {"Content-Type" => "text/plain"})'));
        $this->assertSame(['POST', '@tests/fixtures/read_me.txt', 'text/plain', '27'], [$request['method'], $request['body'], $request['headers']['content-type'], $request['headers']['content-length']]);

        // Compared in GazLang, since JSON can't carry bytes that aren't UTF-8 back
        $this->assertSame('true', $this->gaz('$body = repeat("a\0\xff", 100000); print(http_post("{$url}/body", $body)["body"] == $body);'));
    }

    public function test_other_methods_and_head()
    {
        $request = self::request($this->call('http_request("PUT", "{$url}/thing", {}, "data")'));
        $this->assertSame(['PUT', 'data'], [$request['method'], $request['body']]);
        $this->assertSame('DELETE', self::request($this->call('http_request("DELETE", "{$url}/thing")'))['method']);
        $request = self::request($this->call('http_request("GET", "{$url}/thing", {}, "query")'));
        $this->assertSame(['GET', 'query'], [$request['method'], $request['body']]);

        $head = $this->call('http_request("HEAD", "{$url}/thing")');
        $this->assertSame([200, 'application/json', ''], [$head['status'], $head['headers']['content-type'], $head['body']]);
    }

    public function test_an_error_status_is_a_response()
    {
        $response = $this->call('http_get("{$url}/missing")');

        $this->assertSame([404, 'not here'], [$response['status'], $response['body']]);
    }

    public function test_redirects_are_followed_as_browsers_do()
    {
        $landed = fn (string $call) => self::request($this->call($call));

        $request = $landed('http_get("{$url}/redirect")');
        $this->assertSame(['GET', '/landed?from=redirect'], [$request['method'], $request['uri']]);
        $this->assertSame('/landed', $landed('http_get("{$url}/relative")')['uri'], 'relative to the redirecting path');

        // A 303, and a 301 or 302 after a POST, become a GET without the body or its type
        $request = $landed('http_post("{$url}/see-other", "form", {"Content-Type" => "text/plain"})');
        $this->assertSame(['GET', '', null], [$request['method'], $request['body'], $request['headers']['content-type'] ?? null]);
        $this->assertSame('GET', $landed('http_post("{$url}/redirect", "form")')['method']);
        $this->assertSame('PUT', $landed('http_request("PUT", "{$url}/redirect", {}, "data")')['method']);
        // A 307 keeps both
        $request = $landed('http_post("{$url}/temporary", "form")');
        $this->assertSame(['POST', 'form'], [$request['method'], $request['body']]);
        $this->assertSame(200, $this->call('http_request("HEAD", "{$url}/see-other")')['status']);
    }

    public function test_credentials_go_only_to_the_server_they_were_written_for()
    {
        $headers = '{"Authorization" => "Bearer secret", "Cookie" => "a=1", "X-Other" => "kept"}';

        $same = self::request($this->call("http_get(\"{\$url}/redirect\", {$headers})"))['headers'];
        $this->assertSame(['Bearer secret', 'a=1', 'kept'], [$same['authorization'], $same['cookie'], $same['x-other']]);

        // localhost is another host than 127.0.0.1, though it is the same server
        $other = self::request($this->call("http_get(\"{\$url}/elsewhere\", {$headers})"))['headers'];
        $this->assertSame([null, null, 'kept'], [$other['authorization'] ?? null, $other['cookie'] ?? null, $other['x-other']]);
        $this->assertStringStartsWith('localhost:', $other['host']);
    }

    public function test_bodies_arrive_whole_however_they_are_framed()
    {
        $this->assertSame("Wikipedia in\r\n\r\nchunks.", $this->call('http_get("{$url}/chunked")')['body']);
        $this->assertSame(str_repeat('0123456789', 200000), $this->call('http_get("{$url}/large")')['body']);
        $this->assertSame(str_repeat('x', 2000000), $this->call('http_get("{$url}/large-chunked")')['body']);
        $this->assertSame('all of it', $this->call('http_get("{$url}/until-close")')['body']);
        $interim = $this->call('http_get("{$url}/interim")');
        $this->assertSame([200, 'ok'], [$interim['status'], $interim['body']]);
        $empty = $this->call('http_get("{$url}/no-content")');
        $this->assertSame([204, ''], [$empty['status'], $empty['body']]);
        $this->assertSame('one, two', $this->call('http_get("{$url}/repeated")')['headers']['x-many']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function failures(): array
    {
        return [
            'cut short' => ['http_get("{$url}/truncated")', 'HTTP error: the connection closed in the middle of the body'],
            'not HTTP' => ['http_get("{$url}/garbage")', 'HTTP error: not an HTTP response: "hello"'],
            'no answer' => ['http_get("{$url}/silent")', 'HTTP error: the connection closed before a response came'],
            'redirected for ever' => ['http_get("{$url}/loop")', 'HTTP error: more than 20 redirects'],
            // The system words these, differently from one to another
            'refused' => ['http_get("http://127.0.0.1:1/")', 'Cannot connect to 127.0.0.1 port 1: '],
            'no such host' => ['http_get("http://nowhere.invalid/")', 'Cannot find host nowhere.invalid: '],
        ];
    }

    /**
     * @dataProvider failures
     */
    public function test_a_request_without_a_proper_response_is_an_error(string $call, string $message)
    {
        $this->assertStringStartsWith($message, $this->failure($call));
    }

    public function test_https_checks_the_certificate_against_the_trusted_ones_and_the_host()
    {
        $trusted = ['SSL_CERT_FILE' => self::ROOT.'/tests/fixtures/tls/cert.pem'];
        $port = self::$tlsPort;

        $request = self::request($this->call("http_post(\"https://localhost:{$port}/secret\", \"sealed\")", $trusted));
        $this->assertSame(['POST', '/secret', 'sealed', "localhost:{$port}"], [$request['method'], $request['uri'], $request['body'], $request['headers']['host']]);
        $this->assertSame(str_repeat('x', 2000000), $this->call("http_get(\"https://localhost:{$port}/large-chunked\")", $trusted)['body']);

        // Not among the system's trusted certificates; and one for localhost, not this address.
        // OpenSSL's wording varies between versions
        $this->assertStringStartsWith('TLS error with localhost: ', $this->failure("http_get(\"https://localhost:{$port}/\")"));
        $this->assertStringStartsWith('TLS error with 127.0.0.1: ', $this->failure("http_get(\"https://127.0.0.1:{$port}/\")", $trusted));
        // Plain HTTP to a TLS server gets no HTTP back (a reset, or bytes that aren't HTTP)
        $this->assertNotSame('no error', $this->failure("http_get(\"http://localhost:{$port}/\")"));
    }

    public function test_a_socket_is_a_handle_that_closes()
    {
        $port = substr(self::$url, strrpos(self::$url, ':') + 1);

        $this->assertSame(
            "socket socket true false\nsocket (closed)\nsocket_read() on a closed socket\nsocket_write() on a closed socket\n",
            $this->gaz("\$s = socket_open(\"127.0.0.1\", {$port}); \$t = \$s; \$u = socket_open(\"localhost\", {$port});"
                .' echo "$s " .. type_of($s) .. " " .. ($s == $t) .. " " .. ($s == $u);'
                .' socket_close($t); socket_close($s); echo $s;'
                .' try { socket_read($s); } catch (Error $e) { echo $e.message; }'
                .' try { socket_write($s, "x"); } catch (Error $e) { echo $e.message; }')
        );
    }

    public function test_a_read_that_waits_too_long_is_an_error()
    {
        // Connections queue at a socket nobody accepts from, and never hear anything
        $quiet = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($quiet);
        $address = (string) stream_socket_get_name($quiet, false);
        $port = substr($address, strrpos($address, ':') + 1);

        $start = microtime(true);
        $this->assertSame('socket_read() timed out', $this->failure("socket_read(socket_open(\"127.0.0.1\", {$port}, false, 0.3))"));
        $this->assertLessThan(5, microtime(true) - $start);
        fclose($quiet);
    }

    public function test_the_cat_facts_example_pages_through_the_api()
    {
        $run = fn (string ...$args) => $this->runProgram('examples/cat_facts.gaz', ['--api', self::$url, ...$args]);
        $fact = fn (int $n) => "Fact {$n}".str_repeat('!', $n);

        // Two pages of ten
        $lines = array_map(fn ($n) => str_pad((string) $n, 2, ' ', STR_PAD_LEFT).'. '.$fact($n), range(1, 12));
        $this->assertSame([implode("\n", $lines)."\n", 0], $run('list', '12'));
        // All there are, and those short enough
        $this->assertSame(25, substr_count($run('list', '30')[0], "\n"));
        $this->assertSame(["1. Fact 1!\n2. Fact 2!!\n3. Fact 3!!!\n", 0], $run('list', '3', '9'));
        $this->assertSame(["1. Fact 1!\n2. Fact 1!\n", 0], $run('random', '2', '8'));
        [$output, $code] = $run('random', '1', '3');
        $this->assertSame(1, $code);
        $this->assertStringStartsWith('Error: No cat fact is 3 characters or shorter', $output);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refused(): array
    {
        return [
            'space in a header name' => ['http_get("http://localhost/", {"Bad Name" => 1});', 'HTTP error: bad header name: "Bad Name"'],
            'line break in a header name' => ['http_get("http://localhost/", {"X-A: b\r\nX-B" => 1});', 'HTTP error: bad header name: "X-A: b\r\nX-B"'],
            'line break in a header value' => ['http_get("http://localhost/", {"X" => "a\r\nY: b"});', 'HTTP error: header X has a line break or NUL byte in its value'],
            'a header http writes' => ['http_get("http://localhost/", {"Content-Length" => 5});', 'HTTP error: header Content-Length is written by http_request'],
            'bad method' => ['http_request("GE T", "http://localhost/");', 'HTTP error: bad method: "GE T"'],
            'body not a string' => ['http_post("http://localhost/", [1]);', 'HTTP error: the body must be a string, got list'],
            'another scheme' => ['http_get("file:///etc/passwd");', 'HTTP error: only http and https URLs, got "file:///etc/passwd"'],
            'no scheme' => ['http_get("localhost/x");', 'HTTP error: bad URL: "localhost/x"'],
            'space in a URL' => ['http_get("http://localhost/a b");', 'HTTP error: bad URL: "http://localhost/a b"'],
            'line break in a URL' => ['http_get("http://localhost/\r\nX: y");', 'HTTP error: bad URL: "http://localhost/\r\nX: y"'],
            'credentials in a URL' => ['http_get("http://me:pw@localhost/");', 'HTTP error: a URL can\'t carry credentials; send an Authorization header: "http://me:pw@localhost/"'],
            'bad port' => ['http_get("http://localhost:99999/");', 'HTTP error: bad port in URL: "http://localhost:99999/"'],
            'no host' => ['http_get("http:///x");', 'HTTP error: no host in URL: "http:///x"'],
            'unclosed address' => ['http_get("http://[::1/");', 'HTTP error: bad URL: "http://[::1/"'],
            'socket port' => ['socket_open("localhost", 0);', 'socket_open() expects a port from 1 to 65535, got 0'],
            'socket host' => ['socket_open("", 80);', 'socket_open() expects a host name'],
            'socket timeout' => ['socket_open("localhost", 80, false, 0);', 'socket_open() expects a timeout above 0 seconds'],
            'socket tls' => ['socket_open("localhost", 80, 1);', 'socket_open() expects bool, got int'],
            'not a socket' => ['socket_read("x");', 'socket_read() expects socket, got string'],
        ];
    }

    /**
     * @dataProvider refused
     */
    public function test_what_can_be_checked_before_connecting_is(string $call, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode('include "'.self::ROOT.'/lib/http.gaz"; '.$call);
    }
}
