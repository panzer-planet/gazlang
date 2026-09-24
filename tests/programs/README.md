# Programs the tests run

Each `.gaz` file here is a test subject: it is run under the sanitizers with its output recorded
(`tests/expected/`), compiled by `BytecodeTest`, mutated by the fuzzer, and trained on by the PGO
build. Some have tests of their own (`FootballTest`, `CsvTest`, `HttpTest`).

`football.gaz` and `football/` are **frozen**. They are the language's biggest test program, the
benchmark workload of `php vm/bench.php`, and 29 recorded runs whose output depends on every rating
and every roll of a seeded match. The football manager game (`games/football/`) started as a copy of
them and is where the game is tuned and grows; changing these for the game's sake would re-record
the language's tests each time. Change them only for the language's sake (a feature they should
show, a VM bug they should catch), and re-record.

`examples/` is the opposite: programs nothing tests. See CLAUDE.md, "Programs are tests or examples".
