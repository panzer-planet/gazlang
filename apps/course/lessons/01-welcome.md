---
unit: Getting started
title: What GazLang is
---
GazLang is a strict, self-hosting scripting language for web servers and command line tools. You
run it with one program, `gaz`, which holds the compiler, the virtual machine and the whole
standard library: there is nothing else to install.

Three ideas run through everything you'll meet in this course:

- It would rather stop than guess. Adding a string to a number is an error, not a quiet
  conversion, and a missing key in a map is an error unless you ask for a default. Every error
  names its file and line.
- Values behave like values. Lists and maps are copied when you assign them, like numbers are, so
  nothing changes behind your back. Objects are the exception: they are shared on purpose.
- Batteries are included. JSON and CSV, an HTTP client and a preforking web server, SQLite and
  PostgreSQL, HTML templates, password hashing, regular expressions that can't hang, and a test
  runner all come with `gaz`.

Here is a small taste:

```gaz
$names = ["Ada", "Grace", "Alan"];
foreach ($names as $name) {
    echo "Hello, {$name}!";
}
```

```output
Hello, Ada!
Hello, Grace!
Hello, Alan!
```

The course starts with installing `gaz` and writing a first program, walks through the language,
then the standard library, and ends with a web app: a server, HTML, a database, sessions, tests
and deployment. Every example in it is checked against a real `gaz`, so what a lesson says a
program prints is what it prints.

GazLang is a young hobby language. It is fast (around PHP's speed, and quicker than Python) and
checked carefully, but it makes no promise yet about what changes between releases.

## Question
What is `gaz`?

- [ ] A package manager that installs the GazLang compiler
- [x] The one program that compiles and runs GazLang, with the standard library built in
- [ ] A web framework written in GazLang
