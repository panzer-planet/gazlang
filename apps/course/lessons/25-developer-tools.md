---
unit: The toolkit
title: Tools for every day
---
`gaz` is more than a way to run a file. Everything here is built into the one program.

## Watching for changes

```bash
gaz --watch main.gaz
```

runs the program and runs it again whenever a file it is made of changes: the main file, every
file it imports and its templates. A server is stopped gracefully and started again, so a save
shows up a second later. A compile error is printed, and it waits for your next save.

## A static file server

```bash
gaz -S localhost:8000 --docroot public
```

serves a directory's files, with no program to write: handy for a page of HTML and CSS.

## Seeing how gaz reads your code

`--tokens` shows what the lexer reads, `--ast` the tree the parser builds, and `-c` the bytecode the
compiler writes. They work on a file or on `-e`:

```bash
gaz --tokens -e 'echo 1 + $x;'
```

```output
1 ECHO echo
1 INTEGER 1
1 PLUS +
1 VAR_IDENTIFIER $x
1 SEMICOLON ;
1 EOF
```

Bytecode is text, one instruction a line, and `gaz` runs it too:
`gaz -c app.gaz > app.gzb && gaz app.gzb`.

## Terminal programs without a terminal

`std/tui.gaz` builds full-screen terminal programs. `gaz --tty` runs one on a pretend terminal:
its standard input is the keys, and it prints each screen as text, so a script or a test can drive
it:

```bash
gaz --tty=60x12 app.gaz <<< 'down down snap enter'
```

## Your editor

A clone of the repository has what an editor needs:

- `editors/` holds TextMate grammars for GazLang (`.gaz`), templates (`.gazml`) and bytecode
  (`.gzb`), which VS Code, Sublime Text and most editors read.
- `lsp/server.gaz` is a language server: run as `gaz lsp/server.gaz`, it gives an editor errors as
  you type, hover documentation, go-to-definition across imports, and completion.
- The documentation comments above `pub` names (`/** ... */`) are what hover shows, and what the
  library's pages on the website are built from.

## Question
Which command runs your server and restarts it whenever you save a file it imports?

- [x] `gaz --watch main.gaz`
- [ ] `gaz -S main.gaz`
- [ ] `gaz test main.gaz`
