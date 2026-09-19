<?php

// The server HttpTest runs lib/http.gaz against, as `php -S 127.0.0.1:PORT http_server.php`:
// every path answers with the request as JSON, except these
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
switch ($path) {
    case '/missing':
        http_response_code(404);
        echo 'not here';

        return;
    case '/redirect':
        header('Location: /landed?from=redirect', true, 302);
        echo 'moving';

        return;
    case '/see-other':
        header('Location: /landed', true, 303);

        return;
    case '/repeated':
        header('X-Many: one', false);
        header('X-Many: two', false);
        echo 'repeated';

        return;
    case '/large':
        echo str_repeat('0123456789', 200000);

        return;
}
header('Content-Type: application/json');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => array_change_key_case(getallheaders()),
    'body' => file_get_contents('php://input'),
], JSON_UNESCAPED_SLASHES);
