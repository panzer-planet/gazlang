---
unit: Web apps
title: Sessions, forms and passwords
---
A web app with accounts needs the same few pieces, and `std/http.gaz` has them as middleware for a
router. Middleware wraps every handler; the first added is the outermost:

```gaz
import "std/crypto.gaz";
import "std/http.gaz";
import "std/web.gaz";

const SECRET = "a secret of at least thirty-two bytes, from the environment";

fn sign_in_form($request) {
    $field = web::csrf_field($request["session"]["csrf_token"]);
    $flash = $request["flash"] ?? "";

    return {"body" => web::html"<p>{$flash}</p><form method=\"post\">{$field}<input name=\"name\"><button>Sign in</button></form>"};
}

fn sign_in($request) {
    $name = http::form($request, {})["name"] ?? "";
    $session = {"name" => $name, "csrf_token" => crypto::token()};

    return http::with_session(http::redirect("/"), http::flash($request, "Welcome, {$name}", $session));
}

$app = http::Router();
$app.use(http::security_headers());
$app.use(http::sessions(SECRET));
$app.use(http::csrf());
$app.get("/sign-in", sign_in_form);
$app.post("/sign-in", sign_in);
```

- `http::security_headers()` adds a Content Security Policy and friends to every response.
- `http::sessions($secret)` gives each request `$request["session"]`, a map kept in a signed
  cookie, and `$request["flash"]`, a message the last request left for this one. A handler changes
  the session by returning a response with `"session"` in it, which `http::with_session()` adds.
- `http::csrf()` refuses a POST that doesn't carry the session's token, which
  `web::csrf_field()` puts in a form.
- `http::form($request, {})` reads a form's fields; the `{}` is what it gives for a body that
  isn't a form, instead of an error.
- `http::redirect()` sends the browser on with a 303, as a form's POST should.
- Signing in builds a new session, with a fresh token, so nothing planted in the old one carries
  over. Who the user is stays your code's business.

## Passwords

Never store a password; store `crypto::hash_password()`'s result, which is Argon2id with a random
salt, and check a login with `crypto::verify_password()`:

```gaz
import "std/crypto.gaz";

$stored = crypto::hash_password("correct horse battery");
echo starts_with($stored, '$argon2id$');
echo crypto::verify_password("correct horse battery", $stored);
echo crypto::verify_password("wrong guess", $stored);
echo len(crypto::token());
```

```output
true
true
false
43
```

`crypto::token()` is 32 random bytes as text, for session ids, reset links and CSRF tokens.

## Question
Why does signing in build a new session rather than adding the user to the old one?

- [x] So nothing an attacker planted in the session before the sign-in carries over
- [ ] Because a session cookie can't be changed once set
- [ ] To make the cookie smaller
