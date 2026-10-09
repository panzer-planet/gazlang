---
unit: The language
title: Lists and maps
---
A list is written `[1, 2, 3]` and indexed from 0; a map is written `{"key" => "value"}` and keeps
its keys in the order they were added. Keys are ints or strings, and `"1"` and `1` are different
keys.

```gaz
$languages = ["gaz", "php"];
$languages[] = "python";
$ages = {"gaz" => 1, "php" => 31};
$ages["python"] = 35;
foreach ($ages as $language => $age) {
    echo "{$language} is {$age}";
}
echo $ages["ruby"] ?? "unknown";
echo [len($languages), in_array("php", $languages), has_key($ages, "gaz")];
```

```output
gaz is 1
php is 31
python is 35
unknown
[3, true, true]
```

`$list[] = value` appends. Reading a key that isn't there is an error; `??` reads it as `null`
instead and gives its right side.

## Values, not references

Lists and maps are values, like numbers: assigning one, or passing it to a function, copies it.
Changing the copy never changes the original.

```gaz
$first = [1, 2];
$second = $first;
$second[] = 3;
echo $first;
echo $second;
```

```output
[1, 2]
[1, 2, 3]
```

## Counting, spreading and taking apart

```gaz
$counts = {};
foreach (split("a b a c a", " ") as $word) {
    $counts[$word] ??= 0;
    $counts[$word]++;
}
echo $counts;

$defaults = {"colour" => "red", "size" => 2};
echo {...$defaults, "size" => 3};
echo [0, ...[1, 2], 3];

[$head, ...$rest] = ["a", "b", "c"];
echo $head .. " then " .. join($rest, ", ");
delete $counts["b"];
echo keys($counts);
```

```output
{"a" => 3, "b" => 1, "c" => 1}
{"colour" => "red", "size" => 3}
[0, 1, 2, 3]
a then b, c
["a", "c"]
```

- `??=` sets a key only when it's missing, which is how a count starts.
- `...` spreads a list into a list or a map into a map; later keys win.
- A list pattern on the left of `=` takes a list apart; `...$rest` takes what's left.
- `delete` removes a key, or an element of a list, moving the later ones down.

## Question
After `$a = [1]; $b = $a; $b[] = 2;`, what is `len($a)`?

- [x] 1
- [ ] 2
- [ ] It's an error to append to a copy
