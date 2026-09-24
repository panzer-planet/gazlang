# Football manager

A terminal football manager game in GazLang, on `lib/tui.gaz`. It lives in this repository so that the
language can be improved as the game asks for it: a change to `lib/` or the VM goes in the same commit
as the game code that needed it.

- `engine/` is the model: players and their positions, squads and formations, fixtures with the
  events of a match, leagues and tables, money and the cup. It started as a copy of the football
  simulator's files (`tests/programs/football/`) and is the game's own now: change it freely.
- `tests/` holds GazLang test programs: each `X_test.gaz` must print exactly the `X_test.expected`
  next to it. Run them with `vendor/bin/phpunit --testsuite games` (0.4s), and the language alone
  with `--testsuite core`; a plain `vendor/bin/phpunit` runs both.

The tests check what stays true however the game is tuned (a season plays every match home and
away, a table adds up, a second yellow card is a sending off), not what a seeded match prints: the
ratings and the odds are meant to change, and a recording would need re-recording with every tweak.

Not here yet: the terminal interface, a career (seasons, promotion, the summer transfer window),
saving and loading. `tests/programs/football.gaz` has a working career loop, saves, and transfers as
a command line program: lift what the game needs from it, and leave it as it is, since it is the
language's biggest test program (see `tests/programs/README.md`).
