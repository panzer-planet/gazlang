---
unit: The toolkit
title: Deploying a server
---
A GazLang server in production is the same program you ran on your machine, with a few choices
made deliberately.

## Behind a reverse proxy

Listen on `127.0.0.1` and put Caddy or nginx in front. The proxy does HTTPS (`gaz` has no TLS on
the server side) and buffers each request whole before passing it on, so a client sending its
body slowly can't hold a worker. With Caddy, a whole site is three lines:

```text
course.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

## Settings from the environment

Keep secrets and settings out of the code, and read them with `getenv()`:

```gaz
$port = to_int(getenv("PORT") ?? "8080", null) ?? throw "PORT must be a number";
$secret = getenv("APP_SECRET") ?? throw "Set APP_SECRET to 32 bytes or more";
```

## Workers and their limits

`workers($n)` decides how many requests are answered at once; a few per CPU core is a good start,
more if handlers wait on a database. `http::serve()` takes options for the rest:

```gaz nocheck
http::serve($listener, $app.handler(), {
    "max_requests" => 1000,      // a worker retires after this many, and a fresh one replaces it
    "handler_timeout" => 10,     // seconds a handler may take before the client gets a 503
    "max_body" => 10485760,      // the largest request body, here 10MB (1MB by default)
});
```

A handler that runs past `handler_timeout` costs one request: the client is answered 503, that
worker ends and the master starts another. With PostgreSQL, set the database's own
`statement_timeout` below it, since a query keeps running on the server after its worker is gone.

## Running it

Run the server under a process manager that restarts it if it dies and sends SIGTERM to stop it.
On SIGTERM `gaz` stops accepting connections, lets every worker finish the request it is in, and
ends. With systemd:

```text
[Service]
WorkingDirectory=/srv/course
Environment=PORT=8080
EnvironmentFile=/srv/course/secrets.env
ExecStart=/usr/local/bin/gaz main.gaz
Restart=on-failure
```

The access log and any errors go to standard error, where systemd's journal (or any process
manager) collects them: a line per request, and a 500's error with its trace.

## Question
Where should HTTPS be handled for a `gaz` web server?

- [x] By a reverse proxy, such as Caddy or nginx, in front of it
- [ ] By `http::serve()`, given a certificate
- [ ] By each worker, with `socket_listen($host, $port, true)`
