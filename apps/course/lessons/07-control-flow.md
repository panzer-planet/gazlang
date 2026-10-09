---
unit: The language
title: Control flow and match
---
`if`, `else if` and `else`, `while`, `for` and `foreach` work as you'd expect, with `break` and
`continue`. The condition always goes in parentheses and the body in braces.

```gaz
$n = 27;
$steps = 0;
while ($n != 1) {
    if ($n % 2 == 0) {
        $n = intdiv($n, 2);
    } else {
        $n = 3 * $n + 1;
    }
    $steps++;
}
echo "27 reaches 1 in {$steps} steps";

for ($i = 0; $i < 10; $i++) {
    if ($i % 3 != 0) {
        continue;
    }
    print($i .. " ");
}
print("\n");
```

```output
27 reaches 1 in 111 steps
0 3 6 9
```

## match

`match` picks a value by comparing its subject with each arm's values, using `==`. Arms are tried
in order, an arm can list several values, and `default` takes the rest:

```gaz
$day = "sat";
$kind = match ($day) {
    "sat", "sun" => "the weekend",
    default => "a weekday",
};
echo "{$day} is {$kind}";
```

```output
sat is the weekend
```

Without a subject, each arm is a condition, and the first that is true wins:

```gaz
$words = [];
for ($i = 1; $i <= 15; $i++) {
    $words[] = match {
        $i % 15 == 0 => "FizzBuzz",
        $i % 3 == 0 => "Fizz",
        $i % 5 == 0 => "Buzz",
        default => to_string($i),
    };
}
echo join($words, " ");
```

```output
1 2 Fizz 4 Buzz Fizz 7 8 Fizz Buzz 11 Fizz 13 14 FizzBuzz
```

`match` is an expression, so it gives a value. There is no fallthrough, and when no arm matches
and there is no `default`, it is an error rather than a quiet `null`:

```gaz
try {
    echo match ("tue") {
        "sat", "sun" => "weekend",
    };
} catch (Error $e) {
    echo $e.message;
}
```

```output
No arm matches "tue"
```

## Question
What happens when no arm of a `match` matches and it has no `default`?

- [x] It's an error
- [ ] It gives `null`
- [ ] The first arm is used
