<?php

// The server HttpTest runs lib/http.gaz against: php http_server.php ADDRESS [CERTIFICATE]
//
// Plain TCP, or TLS with CERTIFICATE (a PEM file holding the certificate and its key). It prints
// the address it listens on (ADDRESS may give port 0), then answers one connection at a time
// until killed. Most paths answer with the request as JSON; the others are written out byte by
// byte, so framing a real server would get right can be got wrong on purpose.
[$address, $certificate] = [$_SERVER['argv'][1], $_SERVER['argv'][2] ?? null];
$context = stream_context_create($certificate === null ? [] : ['ssl' => ['local_cert' => $certificate]]);
$server = stream_socket_server(($certificate === null ? 'tcp://' : 'tls://').$address, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "Cannot listen on {$address}: {$error}\n");
    exit(1);
}
$address = (string) stream_socket_get_name($server, false);
echo $address, "\n";
flush();

// Until killed
while (true) { // @phpstan-ignore while.alwaysTrue
    // A client that refuses the certificate ends the handshake, and with it this connection
    $connection = @stream_socket_accept($server, -1);
    if ($connection === false) {
        continue;
    }
    $head = '';
    while (! str_contains($head, "\r\n\r\n") && ($line = fgets($connection)) !== false) {
        $head .= $line;
    }
    $lines = explode("\r\n", trim($head));
    [$method, $uri] = explode(' ', array_shift($lines)) + ['', ''];
    $headers = [];
    foreach ($lines as $line) {
        [$name, $value] = explode(':', $line, 2) + ['', ''];
        $headers[strtolower($name)] = trim($value);
    }
    $length = (int) ($headers['content-length'] ?? 0);
    $body = $length > 0 ? (string) stream_get_contents($connection, $length) : '';
    fwrite($connection, respond($method, $uri, $headers, $body, $address));
    fclose($connection);
}

/**
 * The bytes to send back
 *
 * @param  array<string, string>  $headers
 */
function respond(string $method, string $uri, array $headers, string $body, string $address): string
{
    $ok = fn (string $content, string $type = 'text/plain') => "HTTP/1.1 200 OK\r\nContent-Type: {$type}\r\nContent-Length: ".strlen($content)."\r\n\r\n".($method === 'HEAD' ? '' : $content);
    $redirect = fn (int $status, string $to) => "HTTP/1.1 {$status} Moved\r\nLocation: {$to}\r\nContent-Length: 6\r\n\r\nmoving";
    $port = substr($address, strrpos($address, ':') + 1);
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

    return match (parse_url($uri, PHP_URL_PATH)) {
        '/body' => $ok($body, 'application/octet-stream'),
        '/missing' => "HTTP/1.1 404 Not Found\r\nContent-Length: 8\r\n\r\nnot here",
        '/redirect' => $redirect(302, '/landed?from=redirect'),
        '/relative' => $redirect(302, 'landed'),
        '/see-other' => $redirect(303, '/landed'),
        '/temporary' => $redirect(307, '/landed'),
        '/elsewhere' => $redirect(302, "http://localhost:{$port}/landed"),
        '/loop' => $redirect(302, '/loop'),
        '/repeated' => "HTTP/1.1 200 OK\r\nX-Many: one\r\nX-Many: two\r\nContent-Length: 0\r\n\r\n",
        '/chunked' => "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n4\r\nWiki\r\n5;note=1\r\npedia\r\nE\r\n in\r\n\r\nchunks.\r\n0\r\nX-Trailer: skipped\r\n\r\n",
        '/large' => $ok(str_repeat('0123456789', 200000)),
        '/large-chunked' => "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n".str_repeat("1f40\r\n".str_repeat('x', 8000)."\r\n", 250)."0\r\n\r\n",
        '/until-close' => "HTTP/1.0 200 OK\r\n\r\nall of it",
        '/interim' => "HTTP/1.1 103 Early Hints\r\nLink: </style.css>\r\n\r\nHTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok",
        '/no-content' => "HTTP/1.1 204 No Content\r\n\r\n",
        '/truncated' => "HTTP/1.1 200 OK\r\nContent-Length: 10\r\n\r\nshort",
        '/garbage' => "hello\r\n\r\n",
        '/silent' => '',
        // catfact.ninja's API, for examples/cat_facts.gaz
        '/fact' => $ok(json_encode(catFact((int) ($query['max_length'] ?? PHP_INT_MAX)) ?? (object) []), 'application/json'),
        '/facts' => $ok(json_encode(catFacts((int) ($query['limit'] ?? 10), (int) ($query['page'] ?? 1), (int) ($query['max_length'] ?? PHP_INT_MAX), $address)), 'application/json'),
        default => $ok(json_encode(['method' => $method, 'uri' => $uri, 'headers' => $headers, 'body' => $body], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'application/json'),
    };
}

/**
 * 25 made-up cat facts, "Fact N" and N exclamation marks, so each is longer than the last
 *
 * @return list<array{fact: string, length: int}>
 */
function catFactList(int $max_length): array
{
    $facts = [];
    for ($n = 1; $n <= 25; $n++) {
        $fact = "Fact {$n}".str_repeat('!', $n);
        if (strlen($fact) <= $max_length) {
            $facts[] = ['fact' => $fact, 'length' => strlen($fact)];
        }
    }

    return $facts;
}

/**
 * /fact: the first that is short enough, rather than a random one, so tests know which
 *
 * @return array{fact: string, length: int}|null
 */
function catFact(int $max_length): ?array
{
    return catFactList($max_length)[0] ?? null;
}

/**
 * /facts: a page of them, with a next_page_url that forgets the limit and max_length, as the
 * real one does
 *
 * @return array<string, mixed>
 */
function catFacts(int $limit, int $page, int $max_length, string $address): array
{
    $facts = catFactList($max_length);
    $last_page = max(1, (int) ceil(count($facts) / $limit));

    return [
        'current_page' => $page,
        'data' => array_slice($facts, ($page - 1) * $limit, $limit),
        'last_page' => $last_page,
        'next_page_url' => $page < $last_page ? "http://{$address}/facts?page=".($page + 1) : null,
    ];
}
