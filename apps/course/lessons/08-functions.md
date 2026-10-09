---
unit: The language
title: Functions and closures
---
A function is declared with `fn`, at the top level of a file, and can be called before it is
declared. A parameter can have a default.

```gaz
echo greet("Ada");
echo greet("Grace", "Howzit");

fn greet($name, $greeting = "Hello") {
    return "{$greeting}, {$name}!";
}
```

```output
Hello, Ada!
Howzit, Grace!
```

A function with no `return` gives `null`. A parameter is a copy, like any list or map you assign,
so a function changes what it was given only by returning something new.

## Functions are values

A function's name is a value you can pass around, and `->` writes a function with no name:

```gaz
$double = $x -> $x * 2;
$add = ($a, $b) -> $a + $b;
$twice = ($f, $x) -> $f($f($x));
echo $twice($double, 5);
echo $add(2, 3);

$shout = $text -> {
    $loud = upper($text);
    return $loud .. "!";
};
echo $shout("hello");
```

```output
20
5
HELLO!
```

After `->` comes either one expression, which is what the function gives, or a block in braces,
which gives what its `return` says.

## Closures

A function made inside another can use the variables around it. It takes a copy of each one at
the moment it is made, and from then on that copy is its own: it keeps it between calls, and the
code around it never sees what it does to it.

```gaz
$total = 0;
$add = $amount -> {
    $total += $amount;
    return $total;
};

echo $add(5);
echo $add(10);
echo $total;
```

```output
5
15
0
```

The lambda's own `$total` went from 0 to 5 to 15, but the `$total` outside it is still 0. That is
the same rule lists and maps follow: assigning one copies it, so nothing changes behind your back.
A function you hand to `map()`, to a router or to another part of the program can't quietly change
your variables, and a variable you read is the one you set.

## When you want to share

Sometimes changing the variable outside is the whole point: a callback that collects what it is
given, or a count that the code around it reads afterwards. Then declare the variable `shared`,
and the scope it is written in and every lambda inside it work on one variable:

```gaz
shared $seen = [];
$visit = $page -> {
    $seen[] = $page;
};

foreach (["/", "/about", "/"] as $page) {
    $visit($page);
}
echo $seen;
```

```output
["/", "/about", "/"]
```

Without `shared`, `$seen` would still be `[]` at the end: each change would have gone to the
lambda's copy.

Sharing is written where the variable is made, not where it is used, so anyone reading the code
sees at once that this variable can change from inside a function. Every other variable keeps the
simple rule: what you see is what you set.

## Each declaration is a new variable

`shared $x = ...` makes a new variable each time it runs. Each call of a function gets its own,
and so does each pass of a loop:

```gaz
fn counter() {
    shared $count = 0;
    return () -> {
        $count++;
        return $count;
    };
}

$next = counter();
$next();
$next();
echo $next();

$other = counter();
echo $other();

$clicks = {};
foreach (["save", "open"] as $button) {
    shared $times = 0;
    $clicks[$button] = () -> {
        $times++;
        return $times;
    };
}
$clicks["save"]();
echo $clicks["save"]() .. " " .. $clicks["open"]();
```

```output
3
1
2 1
```

Two counters never interfere, and each button counts its own clicks.

When the state grows past a variable or two, an object is the other way to share: objects are
handles, so a lambda that holds one changes the same object everyone else sees.

```gaz
kind Tally {
    pub #count = 0;
}

$tally = Tally();
$bump = () -> $tally.count++;
$bump();
$bump();
echo $tally.count;
```

```output
2
```

## Question
A lambda does `$total += 5` on a `$total` from the code around it, which isn't `shared`.
What does the code around it see in `$total` afterwards?

- [x] The value it had before: the lambda changed its own copy
- [ ] The value plus 5: closures change the variables they use
- [ ] An error: a lambda can't change a variable from outside it
