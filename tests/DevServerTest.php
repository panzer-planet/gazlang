<?php

namespace GazLang\Tests;

/**
 * `gaz -S host:port [--docroot DIR]`, std/devserver.gaz's static file server, spawned as a real
 * process and asked over a raw socket, as HttpServerTest asks web_server.gaz.
 *
 * The port is whatever is free, so what this prints can't be recorded.
 */
class DevServerTest extends GazLangTestCase
{
    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $free = stream_socket_server('tcp://127.0.0.1:0');
        if ($free === false) {
            throw new \RuntimeException('Cannot find a free port');
        }
        $address = (string) stream_socket_get_name($free, false);
        self::$port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($free);

        $server = proc_open(
            [self::binary(), '-S', '127.0.0.1:'.self::$port, '--docroot', self::ROOT.'/tests/fixtures/static_site'],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            self::ROOT
        );
        if ($server === false) {
            throw new \RuntimeException('Cannot start gaz -S');
        }
        self::$server = $server;
        $line = trim((string) fgets($pipes[1]));
        if (! str_starts_with($line, 'Listening on ')) {
            throw new \RuntimeException("gaz -S never listened: {$line}");
        }
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
     * Send a request and give everything that comes back until the server closes
     */
    private static function exchange(string $request): string
    {
        $socket = stream_socket_client('tcp://127.0.0.1:'.self::$port, $errno, $error, 5);
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

    private static function get(string $path): array
    {
        return self::response(self::exchange("GET {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n"));
    }

    public function test_an_existing_file()
    {
        $response = self::get('/style.css');

        $this->assertSame(200, $response['status']);
        $this->assertSame('text/css', $response['headers']['content-type']);
        $this->assertSame("body { color: red; }\n", $response['body']);
    }

    public function test_the_root_serves_index_html()
    {
        $response = self::get('/');

        $this->assertSame(200, $response['status']);
        $this->assertSame('text/html; charset=utf-8', $response['headers']['content-type']);
        $this->assertSame("<h1>home</h1>\n", $response['body']);
    }

    public function test_a_missing_file_is_a_404()
    {
        $this->assertSame(404, self::get('/nope.txt')['status']);
    }

    public function test_a_traversal_attempt_is_refused_not_served()
    {
        $this->assertSame(404, self::get('/../devserver_test.php')['status']);
        $this->assertSame(404, self::get('/%2e%2e/devserver_test.php')['status']);

        // A %2F is a / inside one segment, so ..%2f is a .. the segment check never saw; read_me.txt
        // is a file that exists one directory above the one served
        $this->assertSame(404, self::get('/..%2fread_me.txt')['status']);
        $this->assertSame(404, self::get('/%2e%2e%2fread_me.txt')['status']);
        $this->assertSame(404, self::get('/sub/..%2f..%2fread_me.txt')['status']);
    }
}
