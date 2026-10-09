---
unit: Structuring programs
title: Errors
---
Every runtime failure can be caught: a failed operator, a missing key, a wrong type, dividing by
zero. `throw` raises one, and `try`/`catch`/`finally` handles it.

```gaz
kind NotFound extends Error {
    fn _(pub string #key) {
        ##_("No such key: {$key}");
    }
}

fn lookup(map $settings, string $key) {
    return $settings[$key] ?? throw NotFound($key);
}

try {
    lookup({"theme" => "dark"}, "font");
} catch (NotFound $e) {
    echo $e.message .. " (the key was " .. $e.key .. ")";
} catch (Error $e) {
    echo "something else went wrong: " .. $e.message;
} finally {
    echo "finished looking";
}
```

```output
No such key: font (the key was font)
finished looking
```

- `Error` is a kind, with `message`, `file`, `line` and `trace`. Extend it for errors of your own,
  and catch a kind to handle only that one.
- `throw` is an expression, so it fits anywhere a value does: after `??`, in a `match` arm, in a
  lambda.
- `finally` runs however the `try` is left.

## Keeping the cause

An error that wraps another can keep it as its cause, so the whole story is printed when nothing
catches it:

```gaz
try {
    try {
        intdiv(1, 0);
    } catch (Error $e) {
        throw Error("Could not split the bill", $e);
    }
} catch (Error $e) {
    echo $e.message;
    echo "because: " .. $e.cause.message;
}
```

```output
Could not split the bill
because: Division by zero
```

An error nobody catches stops the program and prints its message, where it happened, the calls
that led there and its causes.

## When failing is normal

Bad input isn't exceptional. Builtins that read input take a default instead of a `try`:
`to_int($text, 0)`, `Status::from($value, Status::Open)`, and `??` for a missing key. Save
`try` for real failures, since a `catch (Error $e)` also catches your own bugs.

## Question
Which of these is true of `throw`?

- [x] It's an expression, so `$value ?? throw "missing"` works
- [ ] It must be a statement of its own
- [ ] It can only throw objects of a kind that extends `Error`
