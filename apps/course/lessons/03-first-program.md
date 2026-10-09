---
unit: Getting started
title: Your first program
---
A GazLang program is a file of statements, each ending in `;`. Save this as `hello.gaz`:

```gaz
// hello.gaz: everything after // is a comment
echo "Hello, world!";
print("print adds no newline, ");
print("so this joins it.\n");
```

and run it:

```bash
gaz hello.gaz
```

```output
Hello, world!
print adds no newline, so this joins it.
```

`echo` prints a value and a newline; `print()` prints it as it is. A comment runs from `//` to
the end of the line, and `/* ... */` can span lines.

## When something is wrong

A mistake stops the program with a message that says what went wrong and where:

```bash
gaz hello.gaz
```

```text
Error: Undefined variable: $nmae at hello.gaz:2
```

Read the last part first: the file and line. GazLang never carries on with a guess, so the line it
names is where to look.

## One-liners and scripts

`gaz -e` runs the code you give it, with no file at all. Single-quote the code so the shell leaves
the `$` signs alone:

```bash
gaz -e 'echo 6 * 7;'
```

```output
42
```

A file can also be a command of its own. Start it with a `#!` line and make it executable;
`args()` is the list of what was typed after its name:

```gaz norun
#!/usr/bin/env gaz
echo args();
```

```bash
chmod +x greet.gaz
./greet.gaz Ada Grace
```

```output
["Ada", "Grace"]
```

## Question
What is the difference between `echo` and `print()`?

- [x] `echo` writes a newline after the value; `print()` doesn't
- [ ] `print()` writes a newline after the value; `echo` doesn't
- [ ] `echo` only prints strings, `print()` prints any value
