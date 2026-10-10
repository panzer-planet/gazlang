# Learn GazLang

A web app where students make an account and take a course on GazLang and its toolkit, from
installing `gaz` to deploying a web app: 24 lessons in six units, each ending with a question that
has to be answered right to pass it, and their progress kept.

Like `apps/todo`, it is here to find out what a real web app needs from the language and the
library. It was written from the docs alone (the README and `docs/`), without reading the other
GazLang in this repository.

## Running it

From the repository's root, or from anywhere else with the path to `main.gaz`:

```bash
bin/gaz apps/course/main.gaz
```

Then open http://localhost:8080. It needs nothing installed: the database is SQLite, a file
(`course.db`, next to `main.gaz`) made and migrated on the first start. The lessons and the
stylesheet are found next to `main.gaz` too (`main_dir()`), whatever the working directory.

| Variable | What it is | Default |
| --- | --- | --- |
| `COURSE_DATABASE` | The database, a `sqlite:` URL | `sqlite:course.db` next to `main.gaz` |
| `COURSE_SECRET` | Signs the session cookies, 32 bytes or more | a new one each start, which signs everybody out |
| `PORT` | The port to listen on | `8080` |
| `WORKERS` | How many requests are answered at once | `4` |

It listens on 127.0.0.1 only; put nginx or Caddy in front for anything public, and for HTTPS.

## Tests

```bash
bin/gaz test apps/course/tests
```

They drive the app through `http::TestClient`, as a browser would, on a database in memory.

## How it is put together

- `lessons/`: the course itself, a Markdown file per lesson (see "Writing a lesson").
- `syllabus.gaz`: reads `lessons/`. Each start fills the database from it (`course::seed()` in
  `schema.gaz`), matching lessons by slug, so progress survives an edit to a lesson.
- `schema.gaz`: opening the database, the migrations and the seeding.
- `store.gaz`: every query, and the kinds the rows become (`User`, `Lesson`, `Choice`).
- `accounts.gaz`: registering, signing in and out, and the forms' checks.
- `learning.gaz`: the course overview by unit, a lesson, and checking an answer.
- `markup.gaz`: the Markdown the lessons are written in, as HTML, its code highlighted by
  `std/highlight.gaz` as the GazLang website's is (the colours in `public/style.css`).
- `pages.gaz`: what the handlers share, such as the layout and who is signed in.
- `app.gaz`: the routes and middleware. `main.gaz` starts it.
- `views/`: the pages, as `.gazml` templates.

## Writing a lesson

A lesson is a file in `lessons/` named for its place and its slug, `03-first-program.md`:

```text
---
unit: Getting started
title: Your first program
---
The lesson: paragraphs, ## headings, - lists, `code`, [links](https://...) and fenced blocks.

## Question
Which command runs hello.gaz?

- [ ] php hello.gaz
- [x] gaz hello.gaz
```

A fence names its language. A `gaz` block is checked by `tests/syllabus_test.gaz`: it must
compile, and when an `output` block follows it (past any `bash` ones) it must print exactly that.
`gaz norun` compiles without running (a program that needs arguments or a network), and
`gaz nocheck` is a piece of a bigger program, left unchecked; `bash`, `gazml`, `output` and `text`
are shown as they are. Restart the app to see a change: `gaz --watch` restarts on the program's own
files, and a lesson is data, not something the program imports.

A lesson's slug is its identity: renaming one deletes its students' progress on it.
