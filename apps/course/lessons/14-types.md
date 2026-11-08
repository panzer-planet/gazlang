---
unit: Structuring programs
title: Types when you want them
---
Types are optional. Leave them out and nothing changes; write one and the value is checked where
it arrives: a parameter, a return, a field. The type goes before what it types.

```gaz
fn area(float $width, float $height): float {
    return $width * $height;
}

echo area(2, 3);

$height = "3";
try {
    echo area(2, $height);
} catch (Error $e) {
    echo $e.message;
}
```

```output
6.0
area() expects $height to be float, got string
```

Nothing is ever converted to fit, with one exception: an int is welcome where a float is asked
for, and arrives as one, so `area(2, 3)` works and gives `6.0`. A string never becomes a number.

## Writing types

- A type is a `type_of()` name (`int`, `float`, `string`, `bool`, `null`, `list`, `map`,
  `function`, `object`...) or the name of a kind, an interface or an enum.
- `?string` means a string or `null`, and `int|float` either.
- A function that returns nothing has the return type `: null`.
- Fields take types too: `pub float #balance = 0;`.

```gaz
fn greet(?string $name = null): string {
    return "Hello, " .. ($name ?? "stranger");
}

echo greet();
echo greet("Ada");
```

```output
Hello, stranger
Hello, Ada
```

## Checked before it runs

When a value's type is plain from the source, a literal say, the mistake is found as soon as the
program is read, even in code that would never run:

```gaz nocheck
fn never_called() {
    return area("wide", 2);   // refused before anything runs
}
```

So types catch some mistakes early and every other mistake exactly where a wrong value arrives,
with a message naming the parameter. Untyped code pays nothing for them.

## Question
A function is declared `fn f(float $x)` and called as `f(2)`. What is `$x`?

- [x] `2.0`: an int is accepted where a float is asked for
- [ ] An error: `2` is not a float
- [ ] `2`, still an int
