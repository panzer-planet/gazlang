# Football manager: design

A terminal football manager in the spirit of Football Manager by Sports Interactive: the
structure and feel, none of its content. Everything here is our own.

## What we take from it

- **A manager's-eye screen**: a bar at the top (club, where the season is, the key that moves time
  on), sections down the left, the section on show, a line of hints at the bottom.
- **Tables you move about in**: squads, fixtures, league tables and, later, transfers are dense
  tables that scroll and sort. Keyboard first: arrows or `j`/`k`, `.` and `,` to sort.
- **Time moves when you say so**: one key, `c`, moves on to the next thing that matters. Nothing
  happens in real time.
- **An inbox that tells you what happened** and asks for decisions.
- **Depth under the tables**: ratings and form, tactics, morale, contracts, money. It should be
  possible to lose a season by getting the small things wrong.

## What we leave

The mouse, the 3D match view, licensed names and leagues, the scale: ten clubs, one league, a
squad of eleven and then more, to begin with.

## Layout

```
 Riverside FC        2026/27 · Matchday 3 of 18 · 4th          c Continue ▸
┌──────────────┐┌─ Squad ─────────────────────────────────────────────────┐
│ 1 Inbox      ││ #  Name          Pos   Age   Rating   Goals             │
│ 2 Squad      ││ 1  K. Hale       GK     22       54       0             │
│ 3 Tactics    ││ ...                                                     │
│ 4 Fixtures   ││                                                         │
│ 5 Table      ││                                                         │
│ 6 Finances   ││ 4-4-2   Team rating 60.0                                │
└──────────────┘└─────────────────────────────────────────────────────────┘
 j/k move  . , sort  o reverse  x reset   1-6 sections  tab focus  c continue  q quit
```

## How it is built

- `engine/`: the model, with no idea it is drawn: players, squads, fixtures and their events,
  leagues that play a matchday at a time, money.
- `game.gaz`: the state of a game (clubs, the manager's club, the inbox) and moving time on. It
  never draws or reads a key.
- `ui.gaz`: a `Shell` cuts the screen up with `tui::Rect` and hands each section a rectangle; a
  `View` per section draws in it. Views only read the game; the shell changes it, so what a key does
  is in one place.
- `main.gaz`: the launcher, and the only file that needs a terminal.
- Widgets that more than this game needs belong in `lib/tui.gaz`, not here (`Rect` and `Table`
  began as this game's).

## Testing

Drawing goes into a `tui::Screen`, so every screen is tested as text and styles with no terminal.
The engine's tests assert what stays true however the game is tuned, never what a seeded run prints.
A launcher smoke test runs the real thing on a pty. See `README.md`.

## The engine's gaps, before this is a game

1. **A squad is only the eleven.** Real squads have reserves and a manager picks the eleven from
   them, so `Team` needs a squad and a lineup, and injuries and suspensions start to matter.
2. **Tactics.** Formation is fixed at 4-4-2 and nothing else steers a match: mentality, width,
   tempo and instructions should move the odds.
3. **The match is instant.** A match day should be watched, minute by minute, from the events the
   engine already emits (they are public for this), with substitutions and tactical changes.
4. **Players are a number.** One rating; a manager wants attributes, a role, morale, fitness and a
   contract.
5. **Balance.** Scorelines like 0-8 come from the simulator's odds, and need tuning before anything
   built on them is fun.
6. **No calendar.** Time is a matchday counter; it needs dates, a transfer window, a cup.
7. **No money on screen** (`engine/money.gaz` has values, wages and a summer window).
8. **No saving.** The frozen simulator's career code (`tests/programs/football.gaz`) is the model.

## Slices, in order

1. **The shell**: sections, tables, an inbox, moving time on. *(done)*
2. **Match day**: a match you watch, with commentary from the events, and a lineup to choose from a
   bigger squad.
3. **Tactics**: formation and mentality that change results.
4. **Transfers and finances**: a market with values and wages, the books, the budget.
5. **Saving and loading.**
6. **Depth**: injuries, morale, contracts, training, youth.
7. **A season after a season**: promotion and relegation, cups, an end-of-season summary.
