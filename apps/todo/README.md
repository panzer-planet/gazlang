# Todo

A small web app on GazLang: register, log in, keep a list of todos, each with a due date and time if
you like, and show all of them, the open ones or the done ones. It exists to find out what a
real web app is like to write, so [FRICTION.md](FRICTION.md) is the part to read; this file is how to
run it. Like `games/`, it lives in this repository so that a change to the library or the VM goes in
the same commit as the app code that needed it.

## Run it

It needs PostgreSQL and a `gaz` built with its driver (`make -C vm` finds libpq). From the
repository's root, or from anywhere else with the path to `main.gaz`:

```bash
createdb gaz_todo
DATABASE_URL=postgres:///gaz_todo \
SESSION_SECRET="$(openssl rand -hex 32)" \
bin/gaz apps/todo/main.gaz
```

It migrates the schema, then listens on `http://127.0.0.1:8080`. The environment says the rest:

| Variable | Meaning | Default |
| --- | --- | --- |
| `DATABASE_URL` | where the database is, e.g. `postgres://user@host/name` | required |
| `SESSION_SECRET` | at least 32 bytes that sign the session cookie | required |
| `HOST`, `PORT` | where to listen | `127.0.0.1`, `8080` |
| `WORKERS` | processes serving requests, each with a connection of its own | `4` |
| `SECURE_COOKIES` | `1` marks the session cookie `Secure`, for use behind HTTPS | off |
| `TRUSTED_PROXIES` | the addresses of the proxies in front, separated by commas, whose `X-Forwarded-For` says who the client is (see `http::client_address()`); without it the connection's own address is the client's | none |
| `TIME_ZONE` | the zone times are shown in and due dates are read in, e.g. `Africa/Johannesburg` (from the system's time zone database) | `UTC` |

The migrations and the stylesheet are found next to `main.gaz` (`main_dir()`), whatever the working
directory.
There is no TLS here, so put a proxy in front for HTTPS (and for slow clients: a proxy that buffers
whole requests keeps a few slow connections from using up the workers).

## Test it

The tests use a database of their own (`postgres:///gaz_todo_test`, or `TODO_TEST_DATABASE_URL`),
which they empty, so never point them at one with anything in it:

```bash
createdb gaz_todo_test
bin/gaz test apps/todo/tests
```

They drive the real router through a `Browser` that keeps cookies (`tests/support.gaz`), so a test
reads like a person using the site, with no server running.

## Due dates

A due date is typed into a `datetime-local` field, which sends a date and a clock reading with no zone,
so it is read as the clocks in `TIME_ZONE` show it and stored as a `timestamptz`. A reading the
clocks skip when they go forward is due when an alarm set for it would ring, the hour after; one they
show twice when they go back is due the first time. Either way the message after saving says so.
The list puts open todos that are due first, soonest first, then the open ones that aren't, then the
done ones, and marks an open todo whose time has passed as overdue.

## What is where

| File | What it does |
| --- | --- |
| `main.gaz` | reads the environment, migrates, listens, and gives each worker its own connection |
| `app.gaz` | the pages and what they do (`Handlers`), and `build_app()`, which wires them to the router |
| `middleware.gaz` | who is logged in, and the routes that need someone to be (the security headers, the session and the CSRF and `Origin` checks are the library's, added in `build_app()`) |
| `forms.gaz` | what a form or the query string sends, read and checked: well-formed text on one line, its length in characters, a due date in the app's zone, and which todos the list shows (`?show=open`) |
| `auth.gaz`, `throttle.gaz` | registering and logging in; refusing a login after 5 wrong passwords for an email from one address, or 20 from one address |
| `users.gaz`, `todos.gaz` | the rows as kinds, and the queries (every todo query names its owner) |
| `database.gaz`, `migrations/` | opening the database; the schema as numbered `.sql` files, each applied once |
| `views/` | the pages as `.gazml` templates, escaped by default |

## What it does about hostile input

Every value reaches SQL through `db::sql"..."`, so none is read as SQL; pages are escaped by the
templates; text must be well-formed UTF-8 on one line, because the database refuses anything else; a
form without the session's CSRF token, or from another origin, is a 403; a login changes the
session, so one planted before it is no use; an email nobody has costs a login the same as one that is
real, and a todo that isn't yours is a 404, as one that doesn't exist is; the list's filter is one of three
names or nothing, so a redirect that keeps it never leaves the site; the stylesheet is served from a
directory with nothing else in it. Wrong logins are counted per client address, so a stranger can't
lock an email out, and `X-Forwarded-For` is believed only from a proxy `TRUSTED_PROXIES` names, so a
client can't choose its own address. `ponytail:` many addresses against one email are slowed only by
Argon2id (see FRICTION.md, 5).
