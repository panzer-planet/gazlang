---
unit: The toolkit
title: Testing with gaz test
---
Tests are GazLang programs. A file whose name ends in `_test.gaz` imports `std/test.gaz`, makes its
checks and ends with `test::done()`:

```gaz
import "std/test.gaz" use expect, throws;

fn slug(string $title): string {
    return $title |> trim |> lower |> replace(" ", "-");
}

expect("spaces become dashes", slug(" Hello World "), "hello-world");
expect("it is lowercase", slug("GAZ"), "gaz");

$number = 42;
throws("a number is refused", () -> slug($number), 'slug() expects $title to be string, got int');

test::done();
```

```output
ok spaces become dashes
ok it is lowercase
ok a number is refused
```

- `expect($label, $actual, $expected)` holds when the two are equal and of the same type; when
  lists or maps differ, the failure says where.
- `throws($label, $function, $message)` holds when calling the function throws that message, and
  can also name the kind of error it must be.
- `test::snapshot($label, $value)` compares a value with a recording kept next to the test, which
  `gaz test --update` writes.
- `test::done()` ends the file with status 1 if any check failed.

`gaz test` finds every `*_test.gaz` under the directories you give it (the current one by
default), runs each in a process of its own, and prints only what failed:

```bash
gaz test tests
```

```text
FAIL breaks: expected 3 (int), got 2 (int)
tests/maths_test.gaz: 2 checks, 1 failed; exited with status 1
1 file run, 1 failed
```

Its exit status is 1 when anything failed, so it drops into CI as it is. A file that checks
nothing fails too, since a test that tests nothing passes by mistake.

## Testing a web app

`http::TestClient` is a browser for one site: it sends requests to your handler as `http::serve`
would, with no server and no network, and keeps cookies between them.

```gaz
import "std/http.gaz";
import "std/test.gaz" use expect;

$app = http::Router();
$app.get("/hello/:name", $request -> ({"body" => "Hello, " .. $request["params"]["name"]}));

$client = http::TestClient($app.handler());
$page = $client.get("/hello/Ada");
expect("it greets by name", [$page["status"], $page["body"]], [200, "Hello, Ada"]);
expect("an unknown page is a 404", $client.get("/nowhere")["status"], 404);
test::done();
```

```output
ok it greets by name
ok an unknown page is a 404
```

`$client.post($path, $fields)` sends a form, `http::form_token($page)` finds the CSRF token a page
carries, and `$client.follow($response)` follows a redirect. This course's own tests drive the app
that way, and check that every example in these lessons prints what it says.

## Question
What does `gaz test` do with a test file that makes no checks?

- [x] Counts it as failed
- [ ] Counts it as passed
- [ ] Skips it without a word
