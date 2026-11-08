---
unit: Web apps
title: HTML that can't be injected
---
A name touching a string, `web::html"..."`, is a tagged string: the text and the values reach
`web::html` separately. It reads the markup as a browser would and writes each value for the
place it lands: escaped in text and in quoted attributes, checked in a URL, and refused where
escaping isn't enough.

```gaz
import "std/web.gaz";

$comment = "<script>alert('hi')</script>";
$profile = "javascript:alert(1)";
$items = map(["Tea & cake", "Coffee"], $item -> web::html"<li>{$item}</li>");

echo web::html"<p>{$comment}</p>";
echo web::html"<a href=\"{$profile}\">profile</a>";
echo web::html"<a href=\"/search?q={$comment}\">search</a>";
echo web::html"<ul>{$items}</ul>";
```

```output
<p>&lt;script&gt;alert(&#39;hi&#39;)&lt;/script&gt;</p>
<a href="about:invalid#blocked">profile</a>
<a href="/search?q=%3Cscript%3Ealert%28%27hi%27%29%3C%2Fscript%3E">search</a>
<ul><li>Tea &amp; cake</li><li>Coffee</li></ul>
```

What `web::html` gives is an `Html`, which goes into other HTML as it is, so fragments nest, and a
handler can return one as its body (sent as `text/html`). An `Html` can't be joined to a string
with `..`, since the string would then be escaped twice.

## Templates

For whole pages, a `.gazml` file is a template: HTML with GazLang in it, compiled into a function
when it is imported.

```gazml
@template page(string $title, list $todos)
<!doctype html>
<title>{{ $title }}</title>
<h1>{{ $title }}</h1>
@if (len($todos) == 0)
  <p>Nothing to do.</p>
@else
  <ul>
  @foreach ($todos as $todo)
    <li>{{ $todo }}</li>
  @endforeach
  </ul>
@endif
```

```gaz nocheck
import "views/page.gazml";

$app.get("/", $request -> ({"body" => page("My list", ["Write a lesson"])}));
```

- `{{ expression }}` writes a value for where it lands, by the same rules as `web::html`: escaped
  in text and attributes, an `Html` (another template, a `web::html` fragment) as it is in text, a
  URL checked. A value where escaping can't make it safe, such as inside `<script>`, is an error
  when the program is read, at the template's line.
- `{!! expression !!}` writes text unescaped and unchecked, so it stands out in review.
- `@if`, `@elseif`, `@else`, `@foreach` and their ends each stand on a line of their own.
- Errors point at the template's own lines.

## Attributes that come and go

A value that is the whole of a quoted attribute's value decides whether the attribute is there:
`null` leaves it out, and on an attribute that is on or off (`checked`, `disabled`, `selected`,
`required` and the rest), `true` writes it bare and `false` leaves it out. `web::html` and
templates do the same:

```gaz
import "std/web.gaz";

foreach (["All", "Open"] as $label) {
    $current = $label == "Open" ? "page" : null;
    echo web::html"<a href=\"/{$label}\" aria-current=\"{$current}\">{$label}</a>";
}
$picked = false;
echo web::html"<input type=\"radio\" name=\"answer\" checked=\"{$picked}\">";
```

```output
<a href="/All">All</a>
<a href="/Open" aria-current="page">Open</a>
<input type="radio" name="answer">
```

So a template writes `checked="{{ $chosen }}"` rather than an `@if` around the attribute. Writing
`checked="false"` yourself would tick the box: a browser reads any value as checked.

## Question
What does `web::html` do with a `javascript:` URL in an `href`?

- [x] Writes `about:invalid#blocked` in its place
- [ ] Escapes it, and writes it as it is
- [ ] Throws an error that stops the request
