---
unit: The language
title: Values, and a language that won't guess
---
Every value has a type, and `type_of()` names it: `int`, `float`, `string`, `bool`, `null`,
`list`, `map`, `function`, `kind` and `object` are the ones you'll meet most.

```gaz
echo type_of(42) .. " " .. type_of(4.2) .. " " .. type_of("42") .. " " .. type_of(null);
echo 7 / 2;
echo intdiv(7, 2);
echo 2 ** 10;
echo 1 == 1.0;
echo "1" == 1;
```

```output
int float string null
3.5
3
1024
true
false
```

`type_of()`, `intdiv()` and the other functions in this lesson are builtins: they are always there,
in every program, with nothing to import. More comes from the standard library, which a program
imports by name, as a later lesson shows.

`..` joins text, converting each side as `echo` would. `/` always gives a float, and `intdiv()`
divides whole numbers. `==` never converts between types: `1 == 1.0` compares two numbers, but a
string is never equal to a number.

## Nothing is converted behind your back

```gaz
try {
    echo "5" + 5;
} catch (Error $e) {
    echo $e.message;
}
echo to_int("5") + 5;
echo to_int("five", 0) + 5;
```

```output
Cannot use + on string
10
5
```

Arithmetic on a string is an error. When text should be a number, say so with `to_int()` or
`to_float()`. Given a second argument, they give that instead of an error when the text isn't a
number, which is how a program reads input without a `try`.

Integers are 64 bits and never overflow silently: a result too big to hold is an error, not a
quiet switch to a float. `null` equals only itself, so `null == 0` and `null == false` are false.

## True and false

In an `if`, a number is true unless it is zero, a string is true unless it is empty (so `"0"` is
true), an empty list or map and `null` are false, and objects and functions are always true.

```gaz
if ("0") {
    echo "\"0\" is true";
}
if (![]) {
    echo "an empty list is false";
}
```

```output
"0" is true
an empty list is false
```

## Question
What does `7 / 2` give?

- [x] `3.5`
- [ ] `3`
- [ ] An error, since 7 isn't divisible by 2
