---
unit: The language
title: Pipelines and working with lists
---
`|>` passes the value on its left to the call on its right, as the first argument:
`$x |> f(a)` is `f($x, a)`. A chain reads in the order it runs, top to bottom:

```gaz
$slug = "  Learn GazLang Today  "
    |> trim
    |> lower
    |> replace(" ", "-");
echo $slug;
```

```output
learn-gazlang-today
```

## map, filter, reduce and sort

These builtins take a function and call it for each element:

```gaz
$scores = [["Ada", 9], ["Alan", 7], ["Grace", 9], ["Linus", 6]];

$passed = filter($scores, $pair -> $pair[1] >= 7);
echo map($passed, $pair -> $pair[0]);
echo reduce($scores, ($total, $pair) -> $total + $pair[1], 0);
echo map(["a", "b"], ($letter, $index) -> "{$index}:{$letter}");

$ranked = sort($scores, ($a, $b) -> [$b[1], $a[0]] <=> [$a[1], $b[0]]);
echo map($ranked, [$name, $score] -> "{$name} {$score}");
```

```output
["Ada", "Alan", "Grace"]
31
["0:a", "1:b"]
["Ada 9", "Grace 9", "Alan 7", "Linus 6"]
```

- A function that takes two parameters is given the index (or key) as the second.
- `sort()` takes a comparison that gives a negative number when `$a` comes first, which `<=>`
  does. Lists compare element by element, so `[$b[1], $a[0]] <=> [$a[1], $b[0]]` sorts by score
  from highest, then by name.
- A parameter can be a list pattern, `[$name, $score]`, that takes each element apart.

## The lists library

For more than `map`, `filter` and `sort`, the standard library has `std/lists.gaz`: counting and
grouping by a key, picking a field out of every map, removing duplicates, and more. Import it, then
call its functions with `lists::` in front:

```gaz
import "std/lists.gaz";

$people = [
    {"name" => "Ada", "team" => "red"},
    {"name" => "Alan", "team" => "blue"},
    {"name" => "Grace", "team" => "red"},
];
echo lists::count_by($people, $person -> $person["team"]);
echo lists::pluck($people, "name") |> join(", ");
echo lists::unique([3, 1, 3, 2, 1]);
```

```output
{"red" => 2, "blue" => 1}
Ada, Alan, Grace
[3, 1, 2]
```

## Question
What is `$x |> f(a)` the same as?

- [x] `f($x, a)`
- [ ] `f(a, $x)`
- [ ] `$x(f, a)`
