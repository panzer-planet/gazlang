---
unit: Structuring programs
title: Changing what can't change
---
A read-only object never changes, which is the point of it. But a program still moves on: an
address gets a new street, a task gets one step further along. The answer is a new object, the old
one with a field or two different, and `with` makes it:

```gaz
readonly kind Address {
    fn _(pub string #street, pub string #city, pub string #country = "South Africa");
}

$home = Address("12 Long Street", "Cape Town");
$moved = $home with (street: "3 Kloof Nek Road");
$abroad = $home with (city: "Lisbon", country: "Portugal");

echo $moved.street .. ", " .. $moved.city;
echo $abroad.city .. ", " .. $abroad.country;
echo $home.street;
```

```output
3 Kloof Nek Road, Cape Town
Lisbon, Portugal
12 Long Street
```

Name the fields you change, in any order, as you would pass arguments by name; every field you
don't name is copied from the old object. `$home` is still on Long Street: nothing anyone holds
moves under them.

Writing `Address("3 Kloof Nek Road", $home.city, $home.country)` by hand gives the same object
today, but it names every field to change one. The day `Address` gains a `#postal_code` with a
default, that call still runs, and quietly resets the postal code to the default. `with` names only
what changes, so the new field is copied like the rest.

## The constructor runs again

`with` doesn't copy the object's memory: it calls the kind's constructor with the new values and
the old ones. So everything the constructor checks still holds for the copy, and anything it works
out is worked out again:

```gaz
readonly kind Rectangle {
    pub int #area;

    fn _(pub int #width, pub int #height) {
        if (#width <= 0 || #height <= 0) {
            throw "A rectangle needs a positive width and height";
        }
        #area = #width * #height;
    }
}

$small = Rectangle(2, 3);
$wide = $small with (width: 10);
echo $wide.area;

try {
    $small with (height: 0);
} catch ($e) {
    echo $e;
}
```

```output
30
A rectangle needs a positive width and height
```

The copy's `area` is 30, not the 6 it would be if the field had been copied. And a copy can't
become a rectangle you couldn't have built directly: whatever the constructor refuses, `with`
refuses too.

## Methods that give a changed copy

Inside a method, `#` is the object itself, so `# with (...)` is how a read-only kind's method moves
on. Name such a method for what the copy is (`advanced()`, `moved_back()`), not as an order
(`advance()`): calling it changes nothing, so a name that sounds like it does invites
`$task.advance();` on a line of its own, which throws the copy away.

```gaz
readonly kind Task {
    fn _(pub string #title, pub int #done = 0, pub int #total = 1);

    pub fn advanced(): Task {
        return # with (done: min(#done + 1, #total));
    }

    pub fn finished(): bool {
        return #done == #total;
    }
}

$tasks = [Task("Write the lesson", total: 2), Task("Test it")];
$tasks[0] = $tasks[0].advanced().advanced();

foreach ($tasks as $task) {
    echo $task.title .. ": " .. ($task.finished() ? "done" : "not yet");
}
echo $tasks[0] == Task("Write the lesson", 2, 2);
```

```output
Write the lesson: done
Test it: not yet
true
```

The list holds the change: `$tasks[0] = ...` puts the new task where the old one was. That is the
usual shape. The values don't change, and the one thing that does (a list, a variable, or a mutable
object such as a board or a screen) says where the current one is. The last line also shows that
two read-only objects with the same fields are `==`, however each was made.

## Nested copies

`with` sits beside `.` and `[`, so it chains, and a copy can be made of a field to put in a copy:

```gaz
readonly kind Point {
    fn _(pub int #x, pub int #y);
}

readonly kind Line {
    fn _(pub Point #from, pub Point #to);
}

$line = Line(Point(0, 0), Point(4, 3));
$longer = $line with (to: $line.to with (x: 8));
echo $longer.to.x .. ", " .. $longer.to.y;
echo ($line with (from: Point(1, 1))).from.x;
```

```output
8, 3
1
```

## What can be copied

Only a read-only kind can be copied with `with`: an object that can change is shared on purpose,
and a copy of it would be a second object pretending to be the first. And each of the
constructor's parameters must be one of its fields, kept as given (`fn _(pub #x, ...)`), since
`with` fills a parameter from the field of the same name:

```gaz
kind Counter {
    fn _(pub int #count);
}

readonly kind Report {
    pub list #lines;

    fn _($title) {
        #lines = [$title];
    }
}

try {
    Counter(1) with (count: 2);
} catch (Error $e) {
    echo $e.message;
}
try {
    Report("Sales") with (title: "Costs");
} catch (Error $e) {
    echo $e.message;
}
```

```output
Counter isn't read-only: with copies a read-only object
Report can't be copied with: its constructor doesn't keep $title as a field
```

`Report` has no field `title` to copy from, so there is nothing to fill `$title` with when it isn't
named. A kind like that offers a method that builds what you need instead.

## Question
`$a` is a read-only `Rectangle(2, 3)` whose constructor works out `#area`. What is
`($a with (width: 4)).area`?

- [x] 12: the constructor runs again with the new width
- [ ] 6: every field not named, `area` included, is copied
- [ ] An error: `area` wasn't named, so it has no value
