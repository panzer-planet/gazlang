<?php

namespace GazLang\Tests;

/**
 * lib/http.gaz against PHP's built-in server running tests/fixtures/http_server.php, which
 * answers most paths with the request it got, as JSON
 *
 * The port is whatever is free, so what these print can't be recorded: they run through
 * succeed() rather than executeCode(), and check the response by its parts.
 */
class HttpTest extends GazLangTestCase
{
    /** @var resource|null */
    private static $server = null;

    private static string $url = '';

    public static function setUpBeforeClass(): void
    {
        // A port the system says is free, let go just before the server takes it
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            throw new \RuntimeException('Cannot find a free port');
        }
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        self::$url = "http://{$address}";
        $server = proc_open([PHP_BINARY, '-S', $address, 'tests/fixtures/http_server.php'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, self::ROOT);
        if ($server === false) {
            throw new \RuntimeException('Cannot start php -S');
        }
        self::$server = $server;
        for ($tries = 0; $tries < 100; $tries++) {
            $connection = @fsockopen('127.0.0.1', (int) substr($address, strrpos($address, ':') + 1));
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(50000);
        }
        throw new \RuntimeException("php -S never listened on {$address}");
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    /**
     * What a call to lib/http.gaz returns, through JSON; the server's URL is $url
     *
     * @return mixed
     */
    private function call(string $call)
    {
        $root = self::ROOT;
        $url = self::quote(self::$url);
        $json = self::succeed([], "include \"{$root}/lib/http.gaz\"; include \"{$root}/lib/json.gaz\"; \$url = {$url}; print(json_encode({$call}));");

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
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

    public function test_get_sends_the_headers_and_gives_the_status_headers_and_body()
    {
        $response = $this->call('http_get("{$url}/path?q=[1]&r={2}", {"X-Test" => "yes", "X-Number" => 5, "X-Empty" => ""})');

        $this->assertSame(200, $response['status']);
        $this->assertSame('application/json', $response['headers']['content-type']);
        $request = self::request($response);
        $this->assertSame('GET', $request['method']);
        $this->assertSame('/path?q=[1]&r={2}', $request['uri'], 'brackets and braces are not curl globs');
        $this->assertSame(['yes', '5', ''], [$request['headers']['x-test'], $request['headers']['x-number'], $request['headers']['x-empty']]);
    }

    public function test_post_sends_the_body_as_it_is()
    {
        // A body starting with @ would be a file name to --data-binary
        $response = $this->call('http_post("{$url}/submit", "@tests/fixtures/read_me.txt", {"Content-Type" => "text/plain"})');

        $request = self::request($response);
        $this->assertSame(['POST', '/submit', '@tests/fixtures/read_me.txt', 'text/plain'], [$request['method'], $request['uri'], $request['body'], $request['headers']['content-type']]);
    }

    public function test_a_body_can_be_large_and_hold_any_bytes()
    {
        // Past Linux's 128KB limit on one argument, which is why curl reads it on standard input
        // Compared in GazLang, since JSON can't carry bytes that aren't UTF-8 back
        $same = $this->call('http_post("{$url}/body", repeat("a\0\xff", 100000), {"Content-Type" => "application/octet-stream"})["body"] == repeat("a\0\xff", 100000)');

        $this->assertTrue($same);
    }

    public function test_other_methods_and_head()
    {
        $request = self::request($this->call('http_request("PUT", "{$url}/thing", {}, "data")'));
        $this->assertSame(['PUT', 'data'], [$request['method'], $request['body']]);
        $this->assertSame('DELETE', self::request($this->call('http_request("DELETE", "{$url}/thing")'))['method']);
        // curl would make a GET with a body a POST unless the method is named
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

    public function test_redirects_are_followed_and_a_303_turns_a_post_into_a_get()
    {
        $response = $this->call('http_get("{$url}/redirect")');
        $this->assertSame(200, $response['status']);
        $this->assertSame('/landed?from=redirect', self::request($response)['uri']);

        $request = self::request($this->call('http_post("{$url}/see-other", "form")'));
        $this->assertSame(['GET', '/landed'], [$request['method'], $request['uri']]);
    }

    public function test_repeated_headers_are_joined_and_large_bodies_arrive_whole()
    {
        $this->assertSame('one, two', $this->call('http_get("{$url}/repeated")')['headers']['x-many']);
        $this->assertSame(str_repeat('0123456789', 200000), $this->call('http_get("{$url}/large")')['body']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function failures(): array
    {
        return [
            'refused' => ['http_get("http://127.0.0.1:1/")', 'HTTP error: Failed to connect to 127.0.0.1'],
            'another protocol' => ['http_get("file:///etc/passwd")', 'HTTP error: Protocol "file" '],
            'malformed' => ['http_get("http://[nope/")', 'HTTP error: '],
        ];
    }

    /**
     * @dataProvider failures
     */
    public function test_a_request_without_a_response_is_a_catchable_error(string $call, string $message)
    {
        // curl words them differently from version to version, so only their start is checked
        $root = self::ROOT;
        $caught = self::succeed([], "include \"{$root}/lib/http.gaz\"; try { {$call}; } catch (Error \$e) { echo \$e.message; }");

        $this->assertStringStartsWith($message, $caught);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refused(): array
    {
        return [
            'space in a header name' => ['http_get("http://localhost/", {"Bad Name" => 1});', 'HTTP error: bad header name: "Bad Name"'],
            'header read from a file' => ['http_get("http://localhost/", {"@file" => 1});', 'HTTP error: bad header name: "@file"'],
            'line break in a header value' => ['http_get("http://localhost/", {"X" => "a\r\nY: b"});', 'HTTP error: header X has a line break in its value'],
            'bad method' => ['http_request("GE T", "http://localhost/");', 'HTTP error: bad method: "GE T"'],
            'body not a string' => ['http_post("http://localhost/", [1]);', 'HTTP error: the body must be a string, got list'],
        ];
    }

    /**
     * @dataProvider refused
     */
    public function test_what_would_reach_curl_as_more_than_a_value_is_refused_before_it_runs(string $call, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode('include "'.self::ROOT.'/lib/http.gaz"; '.$call);
    }
}
