# Football manager

A terminal football manager game in GazLang, on `lib/tui.gaz`. It lives in this repository so that the
language can be improved as the game asks for it: a change to `lib/` or the VM goes in the same commit
as the game code that needed it.

## Run it

    bin/gazlang -f games/football/main.gaz [-- SEED]

A terminal at least 80 columns by 24 rows. `1` to `6` (or the arrows and `enter`) choose a section,
`tab` moves between the menu and the section, `c` starts the next matchday, which you watch (see
below), `C` plays it without watching and `q` quits. In a table:
`j`/`k`, `g`/`G`, page up and down move, `.` and `,` sort by the next or previous column, `o` turns
the order round and `x` puts it back. A SEED makes the same ten clubs again.

## Squad and lineup

A club has a squad of seventeen and picks an eleven from it. On the Squad screen (`2`) the eleven
come first, then the bench, each with its role (`XI` or `sub`) and whether it can play (injured or
banned, in red, with how many matches to go). `enter` picks a player and `enter` on another swaps
them, one from the eleven with one from the bench; `a` picks the best eleven again. There are no
positions to fill: the formation is whatever the eleven add up to (`4-4-2`, or `6-4-0` if you like),
and a defender picked as a forward attacks as a defender does. The other clubs pick their best fit
eleven every matchday; yours stays as you set it, except that a starter who can't play is replaced
by the best fit reserve and the inbox tells you.

Players get hurt in matches (about two in five have an injury, out for a match or two, sometimes
months), and a red card is a match's ban, as is a fifth yellow of the season. A player who is hurt
goes off, and a substitute comes on for him if there is one and a substitution left (three a match).

## Match day

`c` starts a matchday: your match on the screen, minute by minute, with the score and the clock,
commentary newest first (your goals green, theirs red, cards yellow and red), your eleven with their
goals and cards, and the other matches' scores. Time passes by itself, a quarter of a second a
minute. `space` pauses, `+` and `-` change the speed (a minute, two, five or fifteen at a time), `s`
skips to full time, and at full time `c` goes on: the results go in the table and the inbox. Only
`ctrl+c` quits while a match is on.

## What is here

- `engine/` is the model: players and their positions, squads and formations, fixtures with the
  events of a match, leagues that play a matchday at a time, money and the cup. It started as a copy
  of the football simulator's files (`tests/programs/football/`) and is the game's own now.
- `game.gaz` is the state of a game and moving time on; `ui.gaz` is the interface; `main.gaz` is the
  launcher. `DESIGN.md` says where this is going and what the engine still lacks.
- `tests/` holds GazLang test programs: each `X_test.gaz` must print exactly the `X_test.expected`
  next to it. Run them with `vendor/bin/phpunit --testsuite games` (3s, two of them on a real
  terminal), and the language alone with `--testsuite core`; a plain `vendor/bin/phpunit` runs both.

The tests check what stays true however the game is tuned (a season plays every match home and
away, a table adds up, the inbox says what the match said), not what a seeded match prints: the
ratings and the odds are meant to change, and a recording would need re-recording with every tweak.
Every screen is drawn into a `tui::Screen` and read back as text and styles, with no terminal.

Not here yet: match day, tactics, transfers, saving. `tests/programs/football.gaz` has a working career loop, saves, and transfers as
a command line program: lift what the game needs from it, and leave it as it is, since it is the
language's biggest test program (see `tests/programs/README.md`).
