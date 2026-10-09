# Learn GazLang

A small web app where students make an account and take a short course on GazLang: four lessons,
each with a question that has to be answered right to pass it, and their progress kept.

Like `apps/todo`, it is here to find out what a real web app needs from the language and the
library. It was written from the docs alone (the README and `docs/`), without reading the other
GazLang in this repository.

## Running it

From this directory:

```bash
../../bin/gaz main.gaz
```

Then open http://localhost:8080. It needs nothing installed: the database is SQLite, a file
(`course.db`) made and migrated on the first start.

| Variable | What it is | Default |
| --- | --- | --- |
| `COURSE_DATABASE` | The database, a `sqlite:` URL | `sqlite:course.db` |
| `COURSE_SECRET` | Signs the session cookies, 32 bytes or more | a new one each start, which signs everybody out |
| `PORT` | The port to listen on | `8080` |
| `WORKERS` | How many requests are answered at once | `4` |

It listens on 127.0.0.1 only; put nginx or Caddy in front for anything public, and for HTTPS.

## Tests

```bash
../../bin/gaz test tests
```

They drive the app through `http::TestClient`, as a browser would, on a database in memory.

## How it is put together

- `syllabus.gaz`: the course as written. Each start fills the database from it
  (`course::seed()` in `schema.gaz`), matching lessons by slug, so progress survives an edit to
  a lesson.
- `schema.gaz`: opening the database, the migrations and the seeding.
- `store.gaz`: every query, and the kinds the rows become (`User`, `Lesson`, `Choice`).
- `accounts.gaz`: registering, signing in and out, and the forms' checks.
- `learning.gaz`: the course overview, a lesson, and checking an answer.
- `markup.gaz`: a lesson's body (paragraphs, fenced code and inline code) as HTML, its code
  highlighted by `std/highlight.gaz` as the GazLang website's is (the colours in `public/style.css`).
- `pages.gaz`: what the handlers share, such as the layout and who is signed in.
- `app.gaz`: the routes and middleware. `main.gaz` starts it.
- `views/`: the pages, as `.gazml` templates.

## Adding a lesson

Write a function in `syllabus.gaz` returning a `Lesson`, add it to `lessons()` where it belongs,
and restart. A lesson's slug is its identity: renaming one deletes its students' progress on it.
