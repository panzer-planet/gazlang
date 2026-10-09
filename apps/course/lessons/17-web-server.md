---
unit: Web apps
title: A web server
---
A handler is a function from a request to a response, and both are plain maps. `http::serve()`
answers every connection on a listening socket with it:

```gaz norun
import "std/http.gaz";
import "std/json.gaz";

$app = http::Router();

$app.get("/", $request -> ({"body" => "Hello from gaz\n"}));

$app.get("/hello/:name", $request -> {
    $name = $request["params"]["name"];
    $reply = json::encode({"greeting" => "Hello, {$name}"});

    return {"headers" => {"Content-Type" => "application/json"}, "body" => $reply};
});

$app.get("/search", $request -> {
    $query = http::query($request)["q"] ?? "";

    return {"status" => 200, "body" => "You searched for {$query}\n"};
});

$listener = socket_listen("127.0.0.1", 8080);
echo "Listening on http://localhost:8080";
workers(4);
http::serve($listener, $app.handler());
```

```bash
gaz server.gaz &
curl http://localhost:8080/hello/Ada
curl "http://localhost:8080/search?q=tea+and+cake"
```

```output
{"greeting":"Hello, Ada"}
You searched for tea and cake
```

- A request map has `"method"`, `"path"`, `"query"`, `"headers"` and `"body"`; the router adds
  `"params"` for the `:name` parts of its pattern. `http::query()` and `http::form()` decode the
  query string and a form's body into maps.
- A response map has `"status"` (200 if left out), `"headers"` and `"body"`.
- The router answers a path it doesn't know with a 404, a known path with the wrong method with a
  405, and a handler that throws with a 500, logging the error.
- Every request answered is a line on standard error: the time, the client, the method and path,
  the status, the size and how long it took.

## workers(4)

`workers(4)` forks the program into four processes at that line. Each answers one request at a
time, with its own memory, so there is nothing to lock and a crash costs one request, not the
server. The process that called `workers()` stays behind as the master: it accepts connections,
reads each request's headers, and hands whole requests to whichever worker is free, so a slow
client never ties one up. It restarts a worker that dies, and stops them all gracefully on
SIGTERM.

Since the workers share nothing, state that must last belongs in a database or a cookie. Open a
database after `workers()`, so each worker has its own connection.

## Question
What does `workers(4)` do?

- [x] Forks the program into four processes that each answer requests
- [ ] Starts four threads inside one process
- [ ] Limits the server to four connections at a time
