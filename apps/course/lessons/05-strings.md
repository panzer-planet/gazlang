---
unit: The language
title: Strings
---
Double quotes interpolate; single quotes are raw text.

```gaz
const TRIES = 3;
$name = "Ada";
$scores = [9, 7];
$user = {"city" => "Cape Town"};
echo "Hi $name, you scored $scores[0] in {$user["city"]}";
echo "Next turn: {$scores[1] + 1} of {TRIES}";
echo 'Raw: $name {TRIES}';
echo "Braces stay text: {\"ok\": true}";
```

```output
Hi Ada, you scored 9 in Cape Town
Next turn: 8 of 3
Raw: $name {TRIES}
Braces stay text: {"ok": true}
```

The rules are few:

- A bare `$name` interpolates, with one index after it (`$scores[0]`).
- Braces interpolate any expression that starts with a sigil (`{$...}`, `{@...}`, `{#...}`), and
  a constant's name alone (`{TRIES}`). A misspelt constant there is an error, not text.
- Any other brace is text, so JSON and CSS need no escaping.
- In single quotes only `\'` and `\\` are escapes; everything else is kept as written.

## Working with text

```gaz
$line = "  Hello, World  ";
echo lower(trim($line));
echo split("a,b,c", ",");
echo join(["tea", "cake"], " and ");
echo replace("cats like cats", "cats", "dogs");
echo [contains("GazLang", "Lang"), starts_with("GazLang", "Gaz")];
echo slice("GazLang", 3);
$story = "Once";
$story ..= " upon a time";
echo $story;
```

```output
hello, world
["a", "b", "c"]
tea and cake
dogs like dogs
[true, true]
Lang
Once upon a time
```

`..=` appends to a string in place, so building one up in a loop stays fast.

## Bytes and characters

Strings are bytes. `len()` counts bytes, and `upper()` changes only ASCII letters. Three
builtins read text as UTF-8 characters instead:

```gaz
echo [len("café"), utf8_length("café")];
echo utf8_chars("héllo");
echo utf8_valid("caf\xe9");
```

```output
[5, 4]
["h", "é", "l", "l", "o"]
false
```

Check text that comes from outside (a file, a socket) with `utf8_valid()` before treating it as
text. What a web server decodes from a request is checked for you.

## Question
What does `echo 'Hi $name';` print, with single quotes?

- [x] `Hi $name`, exactly as written
- [ ] `Hi` followed by the value of `$name`
- [ ] An error, since `$name` can't be in single quotes
