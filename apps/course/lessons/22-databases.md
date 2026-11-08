---
unit: Web apps
title: Databases
---
`std/db.gaz` speaks SQLite and PostgreSQL through one interface. `db::open()` takes a URL:
`sqlite:app.db` (made if it's missing), `sqlite::memory:`, or `postgres://user:password@host/name`.

SQL is a tagged string, `db::sql"..."`: each value is sent to the database apart from the text, as
a bound parameter, so nothing a value holds is ever read as SQL. A plain string where a query is
expected is an error.

```gaz
import "std/db.gaz";

$db = db::open("sqlite::memory:");
$db.exec(db::sql"create table books (id integer primary key, title text unique, year int)");
foreach ([["Dune", 1965], ["Foundation", 1951], ["Neuromancer", 1984]] as [$title, $year]) {
    $db.exec(db::sql"insert into books (title, year) values ({$title}, {$year})");
}

$before = 1970;
echo $db.query(db::sql"select title from books where year < {$before} order by year");
echo $db.value(db::sql"select count(*) from books");

$wanted = ["Dune", "Neuromancer"];
echo $db.row(db::sql"select sum(year) as total from books where title in {$wanted}");

$id = $db.value(db::sql"insert into books (title, year) values ('Hyperion', 1989) returning id");
echo "Hyperion is book {$id}";
```

```output
[{"title" => "Foundation"}, {"title" => "Dune"}]
3
{"total" => 3949}
Hyperion is book 4
```

- `query()` gives the rows as maps, `row()` the first or `null`, `value()` its first column, and
  `exec()` how many rows changed.
- A list becomes `(?, ?)`, for `in`. Another `db::sql"..."` is spliced in with its values, so a
  condition can be built apart.
- There is no last-insert id: `returning` gives it.

## When the database says no

What the database refuses is a `db::Failure`, whose `problem` says what kind of refusal it was,
the same whichever database said it:

```gaz
import "std/db.gaz";

$db = db::open("sqlite::memory:");
$db.exec(db::sql"create table users (email text unique)");
$db.exec(db::sql"insert into users values ('ada@example.com')");
try {
    $db.exec(db::sql"insert into users values ('ada@example.com')");
} catch (db::Failure $e) {
    echo $e.problem;
}

$db.transaction($db -> {
    $db.exec(db::sql"insert into users values ('grace@example.com')");
    $db.exec(db::sql"insert into users values ('alan@example.com')");
});
echo $db.value(db::sql"select count(*) from users");
```

```output
db::Problem::Unique
3
```

`transaction()` runs its function between a begin and a commit, and rolls everything back if it
throws.

## Question
In `db::sql"select * from books where title = {$title}"`, what does the database receive for
`{$title}`?

- [x] A placeholder in the SQL, with the value sent apart from it as a parameter
- [ ] The value written into the SQL, with quotes escaped
- [ ] An error, since a variable can't go in SQL
