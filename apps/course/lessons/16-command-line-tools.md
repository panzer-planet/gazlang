---
unit: The standard library
title: Command line tools
---
GazLang is meant for tools as much as for servers. The pieces a tool needs are builtins: `args()`
for its arguments, `read_line()` and `read_stdin()` for its input, `read_file()` and
`write_file()`, `getenv()`, and `exit($code)`.

```gaz
$path = "/tmp/gaz-course-notes.txt";
write_file($path, "first\nsecond\n");
foreach (split(trim(read_file($path)), "\n") as $number => $line) {
    echo "{$number}: {$line}";
}
echo file_info($path)["size"] .. " bytes";
delete_file($path);
```

```output
0: first
1: second
13 bytes
```

For a big file, `file_open()` and `file_read_line()` read it a line at a time.

## Arguments, the easy way

`std/cli.gaz` turns a declaration of your tool's flags, options and arguments into parsing,
`--help` and error messages:

```gaz norun
import "std/cli.gaz";

$cli = cli::Command("greet", "Say hello to someone");
$cli.flag("shout", "s", "Say it loudly");
$cli.option("greeting", "g", "What to say", "Hello");
$cli.argument("name", "Who to greet");
$args = $cli.parse(args());

$text = "{$args["greeting"]}, {$args["name"]}!";
echo $args["shout"] ? upper($text) : $text;
```

```bash
gaz greet.gaz Ada --shout
gaz greet.gaz -g Howzit Grace
```

```output
HELLO, ADA!
Howzit, Grace!
```

`gaz greet.gaz --help` prints the help written from those declarations, and a mistake (a missing
name, an unknown option) prints what is wrong and exits with status 2, as command line tools do.
`$cli.command(...)` adds subcommands, each with its own arguments.

## Running other programs

`run()` starts a program and waits for it, giving its status and both of its outputs:

```gaz
$result = run(["echo", "from another program"]);
echo $result["status"];
echo trim($result["stdout"]);
```

```output
0
from another program
```

It takes the program and its arguments as a list, and no shell ever reads them, so nothing in an
argument (a `;`, a `$`, a file name with spaces) can turn into a command. Pass
`{"timeout" => 10}` to stop a program that takes too long.

## Question
Why does `run()` take a list such as `["git", "log"]` rather than one command string?

- [x] No shell reads it, so nothing in an argument can run as a command of its own
- [ ] Lists are quicker to pass to a program than strings
- [ ] So it works on Windows
