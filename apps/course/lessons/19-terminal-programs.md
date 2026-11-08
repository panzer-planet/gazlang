---
unit: The standard library
title: Terminal programs
---
A command line tool prints lines. A terminal program owns the whole screen: a dashboard, a picker,
an editor. Two library files make them: `std/term.gaz` for colours, keys and raw mode, and
`std/tui.gaz` for drawing on a screen in boxes, labels, lists and tables.

`term::style()` wraps text in the escape sequences that colour it. Those bytes take no room on the
screen, so measure what the reader sees with `term::width()`, not `len()`:

```gaz
import "std/term.gaz";

$warning = term::style("careful", "bold yellow");
echo len($warning) .. " bytes, " .. term::width($warning) .. " columns";
echo term::strip($warning);
```

```output
18 bytes, 7 columns
careful
```

A style is words: `bold`, `dim`, `italic`, `underline`, a colour (`red`, `bright_blue`), `on_` and a
colour for the background, or `#rrggbb`. A word it doesn't know is an error, not a quiet mistake.

## Drawing on a screen

A `tui::Screen` is a grid of cells that you draw into. Nothing reaches the terminal until the
screen is rendered, and rendering sends only the cells that changed, so a program can clear and
redraw everything on every key without flicker. Because it is only a grid, a program can draw into
one without a terminal at all, which is how this example prints and how you test a view:

```gaz
import "std/tui.gaz";

$screen = tui::Screen(30, 6);
$panel = $screen.bounds();
tui::box($screen, $panel, "Build");
[$body, $foot] = $panel.inset(1).split_bottom(1);
tui::label($screen, $body.line(0), "Compiling...");
tui::progress($screen, $body.line(1), 0.5);
tui::label($screen, $foot, "q quit", "dim", "right");

foreach ($screen.lines() as $line) {
    echo $line;
}
```

```output
┌─ Build ────────────────────┐
│Compiling...                │
│████████████░░░░░░░░░░░  50%│
│                            │
│                      q quit│
└────────────────────────────┘
```

Everything is placed with a `tui::Rect`, a part of the screen. You never add up columns: you cut a
`Rect` into parts (`inset()`, `split_left()`, `split_bottom()`, `line($n)` for one row of it) and
hand each part to what draws there. A widget given a `Rect` draws inside it and nowhere else.

## Keys, and a program that runs

`tui::interact($draw, $handle)` runs the program: it takes over the screen, calls `$draw` with the
screen to fill, waits for a key, gives it to `$handle`, and draws again, until `$handle` returns
something other than `null`, which `interact()` then returns: here, `"quit"`. It puts the terminal back however the program ends, an error included.

```gaz norun
import "std/term.gaz";
import "std/tui.gaz";

kind Counter {
    pub int #count = 0;

    pub fn up(): null {
        #count++;
    }

    pub fn down(): null {
        #count--;
    }
}

fn draw(tui::Screen $screen, Counter $counter): null {
    $panel = tui::centered($screen, 24, 3);
    tui::box($screen, $panel, "Counter");
    tui::label($screen, $panel.inset(1), "{$counter.count}", "bold", "center");
}

fn handle(Counter $counter, map $key): ?string {
    match (term::name($key)) {
        "up", "+" => { $counter.up(); }
        "down", "-" => { $counter.down(); }
        "q", "ctrl+c" => { return "quit"; }
        default => {}
    }
    return null;
}

$counter = Counter();
tui::interact($screen -> draw($screen, $counter), $key -> handle($counter, $key));
```

A key arrives as a map, and `term::name()` writes it as text (`"a"`, `"enter"`, `"ctrl+c"`,
`"shift+up"`), which is what a `match` wants. Keep drawing and handling keys apart from the state
they show, as `draw()`, `handle()` and `Counter` are here: the state can then be tested alone, and
the drawing into a `Screen` with no terminal.

`std/tui.gaz` also has the widgets a program is built from: `tui::List` and `tui::Table` scroll
and keep a selection, `tui::Menu` picks one thing, and `tui::TextField` edits a line. Each draws on
a `Rect` and handles its own keys. `examples/dashboard.gaz` in the repository is a whole program to
read, and `gaz --tty` (in "Tools for every day") runs one with a script for its keys.

## Question
Why does the example measure a styled string with `term::width()` instead of `len()`?

- [x] The colour's escape sequences are bytes that take no room on the screen
- [ ] `len()` can't take a string with a style in it
- [ ] `term::width()` is faster
