# GazLang

A small scripting language with a PHP-flavoured syntax and its own opinions: values are
what they are (no conversion between strings and numbers, no `===`), lists and maps are values,
functions are values, and errors are loud. Written in PHP, with a tree-walking interpreter
and a stack VM that must agree on everything, on the way to being self-hosting.

```
include "lib/csv.gaz";
include "lib/functional.gaz";

fn report($sales, $column) {
    $totals = reduce($sales, ($t, $row) -> {
        $t[$row["region"]] = ($t[$row["region"]] ?? 0.0) + to_float($row[$column]);
        return $t;
    }, {});
    $regions = sort(keys($totals), ($a, $b) -> $totals[$b] <=> $totals[$a]);
    foreach ($regions as $region) {
        echo "{$region}: {round($totals[$region], 2)}";
    }
}

try {
    report(csv_records(csv_parse(read_file(args()[0] ?? "sales.csv"))), "amount");
} catch ($e) {
    echo "Error: {$e["message"]}";
    exit(1);
}
```

## Install and run

Requires PHP 8.5 or later.

```bash
git clone https://github.com/panzer-planet/gazlang.git
cd gazlang && composer install

php bin/gazlang -f program.gaz                 # compile to VM code and run it
php bin/gazlang -f program.gaz -- arg1 arg2    # arguments for args()
cat program.gaz | php bin/gazlang              # piped input runs as one program
php bin/gazlang --interpreter -f program.gaz   # the tree-walking interpreter instead
php bin/gazlang -c -f program.gaz              # print the VM code
php bin/gazlang --tokens -f program.gaz        # print the tokens
```

Try `php bin/gazlang -f examples/csv_report.gaz -- examples/data/sales.csv region amount`.

## The language

- **Values**: ints (`42`, `0xFF`, never silently overflowing), floats (`1.5`, `2e-3`, always
  finite), byte strings, `true`/`false`, `null`, lists, maps and functions.
- **Variables**: `$x` is local to the function (or the top level), `@x` is global, everywhere.
- **Lists** `[1, 2]` and **maps** `{"key" => 1}` (int or string keys, `"1"` and `1` distinct,
  insertion order) are separate types, copied on assignment: `$l[0]`, `$m["k"]["j"] = v`,
  `$l[] = v`, `foreach ($m as $k => $v)`, `len`, `keys`, `slice`. A missing index or key is an
  error unless read with `??`.
- **Strings**: `"..."` with escapes (`\n`, `\xHH`, `\u{1F600}`) and interpolation (`"Hi $name"`,
  `"{$user["name"]} has {@count}"`); `'...'` raw. `..` concatenates, converting like `echo`.
- **Operators**: `+ - * / %` on numbers only (`/` always gives a float; `intdiv` for ints);
  `== !=` with no conversion between types (`"5" == 5` is false, `1 == 1.0` is true, lists and
  maps compare element by element); `< <= > >=` and `<=>` on numbers or on strings; `&& || !`;
  `& | ^ << >> ~` on ints only, above the comparisons as in Rust and Python, so
  `$flags & MASK == 0` is `($flags & MASK) == 0`; `??` and `??=` for missing values;
  `$c ? $a : $b`; `+= -= *= /= %= ..= &= |= ^= <<= >>= ++ --`.
- **Control flow**: `if`/`else if`/`else`, `while`, `for`, `foreach`, `break`, `continue`, and
  `match ($x) { 1, 2 => "few", default => "many" }`, an expression whose arms are compared with
  `==` and tried in order; written as a statement, an arm may be a block.
- **Functions**: `fn add($a, $b = 1) { return $a + $b; }` at the top level, callable
  before they are declared. A bare name is a value (`$f = add; $f(1)`, builtins too), and
  `$x -> $x * 2`, `($a, $b = 1) -> $a + $b`, `() -> { return 42; }` are anonymous functions
  that copy the outer variables they use when created and keep them between calls
  (`() -> ++$n` counts; a plain `=` inside makes a variable local to the call). A lambda
  assigned with `$f = ...` can call `$f` inside.
- **Errors**: `error("message")` raises one, `try { } catch ($e) { }` catches any runtime
  error as `{"message" => ..., "file" => ..., "line" => ...}`, and uncaught errors print
  `Error: ... at file.gaz:12`. `exit($code)` stops the program.
- **Builtins**: `len`, `slice`, `lower`, `upper`, `trim`, `split`, `join`, `replace`, `contains`,
  `starts_with`, `ends_with`, `index_of`, `repeat`, `chr`, `ord`, `to_int`, `to_float`,
  `to_string`, `floor`, `ceil`, `round`, `abs`, `intdiv`, `min`, `max`, `in_array`, `has_key`,
  `keys`, `values`, `type_of`, `is_a`, `print`, `print_error`, `error`, `exit`, `read_file`,
  `write_file`, `read_stdin`, `args`.
- **Libraries in GazLang** (`lib/`): `functional.gaz` (`map`, `filter`, `reduce`, `sort`),
  `json.gaz`, `csv.gaz`, `chars.gaz`, `format.gaz`, `sort.gaz`; `include "lib/json.gaz";`
  includes a file once, relative to the including file.
- **Comments**: `// to the end of the line`.

The design decisions and their reasons are in `CLAUDE.md`; `docs/design-review.md` lists the
ones still open.

## Layout

- `src/Lexer`, `src/Parser`, `src/AST`: source text to a tree.
- `src/Runtime`: what values mean (`Values`) and the builtins, shared by both backends.
- `src/Interpreter`: runs the tree. `src/CodeGenerator` and `src/VM`: compile it to
  stack VM code and run that, the default.
- `lib/`: libraries written in GazLang. `examples/`: sample programs.
- `tests/`: PHPUnit; every snippet runs on both backends and must match. `tests/gaz/`
  holds GazLang test programs with their expected output.

## License

MIT
