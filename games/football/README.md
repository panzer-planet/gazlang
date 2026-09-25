# Football manager

A terminal football manager game in GazLang, on the standard library's `std/tui.gaz`. It lives in this repository so that the
language can be improved as the game asks for it: a change to `lib/` or the VM goes in the same commit
as the game code that needed it. Its only outward dependency is the built-in standard library (`include "std/..."`),
so moving it to a repository of its own needs nothing but a `gaz` binary.

## Run it

    bin/gaz games/football/main.gaz [SEED | load [FILE]]

A terminal at least 80 columns by 24 rows. `1` to `7` (or the arrows and `enter`) choose a section,
`tab` moves between the menu and the section, `c` moves time on (see "The calendar") to your next
match, which you watch (see below), `C` plays it without watching and `q` quits. In a table:
`j`/`k`, `g`/`G`, page up and down move, `.` and `,` sort by the next or previous column, `o` turns
the order round and `x` puts it back. A SEED makes the same forty-four clubs again; `S` saves and `load` carries on (see below).

## Squad and lineup

A club has a squad of seventeen and picks an eleven from it. On the Squad screen (`2`) the eleven
come first, then the bench, each with its role (`XI` or `sub`) and whether it can play (injured or
banned, in red, with how many matches to go). `enter` picks a player and `enter` on another swaps
them, one from the eleven with one from the bench; `a` picks the best eleven again. There are no
positions to fill: the formation is whatever the eleven add up to (`4-4-2`, or `6-4-0` if you like),
and a defender picked as a forward attacks as a defender does. The other clubs pick their best fit
eleven every matchday; yours stays as you set it, except that a starter who can't play is replaced
by the best fit reserve and the inbox tells you.

Players get hurt in matches (about two in five have an injury) and are out for days, not matches:
ten days to three months, healing a day at a time whether or not there is a match (the Squad
screen says `Injured 9d` or `Injured 4w`, and the inbox tells you when someone is back). A red card
is a match's ban, as is a fifth yellow of the season. A player who is hurt goes off, and a
substitute comes on for him if there is one and a substitution left (three a match).

## Match day

`c` starts a matchday: your match on the screen, minute by minute, with the score and the clock,
commentary newest first (your goals green, theirs red, cards yellow and red), your eleven with their
goals and cards, and the other matches' scores. Time passes by itself, a quarter of a second a
minute. `space` pauses, `+` and `-` change the speed (a minute, two, five or fifteen at a time), `s`
skips to full time, and at full time `c` goes on: the results go in the table and the inbox. Only
`ctrl+c` quits while a match is on.

`w` makes a substitution (who goes off, then who comes on, with the clock stopped; `escape` cancels;
three a match), `[` and `]` make the side more defensive or more attacking and `i` cycles how hard it
presses. The Tactics screen (`3`) shows the eleven as a picture and sets the formation (which picks
the best eleven for it), mentality and intensity, with what each does. A side that attacks scores
and concedes more; one that presses hard attacks a little more and tires faster. The other clubs
set up by how they rate against who they play.

## Money and transfers

Every matchday each club is paid by the television and pays a matchday's wages, and the home side
takes the gate; Finances (`6`) shows the balance and every entry, green in and red out. Transfers
(`7`) lists every player at the other clubs with the price they ask (a third over his value); `enter`
buys, and `t` switches to your own squad, where `enter` sells at a tenth under his value to the
richest club that can pay, or abroad if none can. A squad is 16 to 24 players with at least two
goalkeepers, and nothing is bought or sold during a match.

## The calendar

The game has a date, shown in the top bar, and time moves a day at a time. `c` carries on until
something needs you and stops there: your next match (which you watch), or an event the inbox tells you
about. `C` does the same but plays your match at once. Days that matter to nobody go by: the other
division's matches are played out unwatched, and everyone's birthday comes round (a player's age goes
up on his birthday).

A season starts on 1 July. The first matches are on the first Saturday from 8 August and the last by 16
May: the Premier Division's thirty-eight matchdays are Saturdays with a few weeks' break, and the
Championship's forty-six the same Saturdays plus five Tuesday nights. On 1 June, after the last match, the
season is reviewed (below) and the next one is drawn. The **transfer windows** are July and August, and
January: outside them Transfers refuses to buy or sell, and says when it opens. On 1 July the other clubs
do their business, which the inbox tells you of if it involved you.

## The Cup

Every club in both divisions is in one knockout cup, on Wednesdays through the season and the final on a
Saturday in late May. The twenty-four weakest clubs play a first round on the middle of September, the
twenty Premier Division clubs joining in the second, then thirty-two, sixteen, the quarter-finals, semi-finals
and final: six rounds, each drawn from a hat when its day comes. A tie level after ninety minutes goes to extra
time and then penalties, which you see as commentary. When your club is in the round, `c` stops for your tie and
you watch it like a league match; a win pays the club a prize that grows each round, and the home club takes
the gate. The Fixtures screen lists the cup ties among your matches, and the inbox has each round's
results. If the league finishes before the final, the season's review waits for it.

## Contracts

Every player has a contract that runs out on a 30 June (the Squad screen shows the year). A player
whose contract runs out leaves, so renew in the last two years with `r` on the Squad screen: a
sixteenth of his value as a signing-on fee, for as many years as his age deserves (four up to 27, two to
31, then one). On 1 January the inbox warns you who is out of contract on 30 June, and on 30 June those who
weren't renewed go and the academy fills the squad with youngsters. The other clubs renew theirs. Signings
get three seasons; a club asks half as much for a player with under six months left (he would leave for
nothing). The market shows each player's contract.

## Saving, and seasons

`S` saves the game to `football.save` (between matchdays, not during a match), and
`bin/gaz games/football/main.gaz load [FILE]` carries on from it. On 1 June, after the last
match, the season is reviewed: prize money by where you finished, promotion and relegation, a summer's
development for every player (ratings move by age), and veterans retiring for youngsters from the
academy. Then the new season's fixtures are drawn.

## Two divisions

Forty-four clubs: twenty in the Premier Division (thirty-eight matchdays) and twenty-four in the
Championship (forty-six). `bin/gaz games/football/main.gaz SEED` starts you at the first
club; the club is an index into the list in `game.gaz` (from the twenty-first on you start in the
Championship). Both divisions are on the same calendar; the Championship, with more matchdays, plays on a
few midweek nights and on the last Saturday after the Premier Division's season is over (`c` passes
over those). The table shows yours (`d` looks at the other), with the places that go up green and
those that go down red. At the season's review the bottom two of the Premier Division and
the top two of the Championship swap, and the prize money is bigger in the top division. A club is
only paid and only pays wages for matches it plays. Saves from before the cup won't load.

## What is here

- `engine/` is the model: players and their positions, squads and formations, fixtures with the
  events of a match, leagues that play a matchday at a time, a cup that plays a round at a time, the
  calendar and money. It started as a copy
  of the football simulator's files (`tests/programs/football/`) and is the game's own now.
- `game.gaz` is the state of a game and moving time on; `ui.gaz` is the interface; `main.gaz` is the
  launcher. `DESIGN.md` says where this is going and what the engine still lacks.
- `tests/` holds GazLang test programs: each `X_test.gaz` must print exactly the `X_test.expected`
  next to it. Run them with `vendor/bin/phpunit --testsuite games` (under a minute, two of them on a real
  terminal), and the language alone with `--testsuite core`; a plain `vendor/bin/phpunit` runs both.

The tests check what stays true however the game is tuned (a season plays every match home and
away, a table adds up, the inbox says what the match said), not what a seeded match prints: the
ratings and the odds are meant to change, and a recording would need re-recording with every tweak.
Every screen is drawn into a `tui::Screen` and read back as text and styles, with no terminal.

Not here yet: European and league cups, free agents, morale, training. `tests/programs/football.gaz` has a working career loop, saves, and transfers as
a command line program: lift what the game needs from it, and leave it as it is, since it is the
language's biggest test program (see `tests/programs/README.md`).
