---
unit: Structuring programs
title: Constants and statics
---
A constant is a name for a value that never changes: `const NAME = value;`, at the top level of a
file or inside a kind. Its value is worked out before the program runs, from literals, operators,
lists, maps and other constants, so it can't call a function or read a variable:

```gaz
const VAT = 0.15;
const PRICE = 200;
const TOTAL = PRICE + PRICE * VAT;
const SIZES = ["small", "medium", "large"];

echo TOTAL;
echo "Sizes: {SIZES}";
echo "Tax is {VAT} of the price";
```

```output
230.0
Sizes: ["small", "medium", "large"]
Tax is 0.15 of the price
```

- A constant can be used before the line that declares it, like a function, and one written in
  terms of another works in any order.
- A constant's name alone in braces, `{VAT}`, puts it in a string, as `{$name}` puts a variable.
- Nothing can change one, and a mistyped name (`TOTL`) is an error before the program starts,
  which is the reason to name a string you would otherwise repeat: a typo in `"shipped"` is a bug
  you find later, a typo in `SHIPPED` one you find now.
- Names are written in capitals by convention.

## Constants in a kind

A kind's constants belong to it. Inside the kind they are `#NAME`, like any member; outside it they
are `Kind::NAME`, and only if marked `pub`:

```gaz
kind Order {
    pub const OPEN = "open";
    pub const SHIPPED = "shipped";
    const STATES = [#OPEN, #SHIPPED];

    fn _(pub string #state = #OPEN) {
        if (!in_array(#state, #STATES)) {
            throw "Unknown state {#state}";
        }
    }
}

echo Order().state;
echo Order(Order::SHIPPED).state;
try {
    Order("lost");
} catch ($e) {
    echo $e;
}
```

```output
open
shipped
Unknown state lost
```

`::` names something by where it is declared and `.` goes through a value, so a constant is always
`Order::SHIPPED`, never `$order.SHIPPED`. When the states are a closed set like these, an enum
(the next lesson) says it better; a constant suits a single value with a name.

## Statics

A static belongs to the kind rather than to any one object. A static method needs no object at all:
it is a function that lives in the kind and is called `Kind::name()`. A static field is one slot the
whole kind shares, and unlike a constant it can change:

```gaz
kind Ticket {
    static #issued = 0;

    fn _(pub int #number);

    pub static fn next(): Ticket {
        #issued++;
        return Ticket(#issued);
    }

    pub static fn count(): int {
        return #issued;
    }
}

$a = Ticket::next();
$b = Ticket::next();
echo $a.number .. " and " .. $b.number;
echo Ticket::count() .. " issued";
```

```output
1 and 2
2 issued
```

Inside the kind a static is `#name`, as every member is. A static method has no object, so it can't
use the object's fields or `#` on its own. A static field needs a value where it is declared.

The most common static method is a second way to make an object. A constructor takes the fields;
a static method takes whatever the object is made from (a database row, a form, a line of a file)
and says so in its name:

```gaz
readonly kind User {
    fn _(pub int #id, pub string #name);

    pub static fn from_row(map $row): User {
        return User($row["id"], $row["name"]);
    }
}

$rows = [{"id" => 1, "name" => "Ada"}, {"id" => 2, "name" => "Grace"}];
$users = map($rows, User::from_row);
echo $users[1].name;
```

```output
Grace
```

`User::from_row` without the parentheses is the function itself, so it can be handed to `map()`.

## A shared value made once

A static field's value can also construct a read-only object, or call a static method. It is made
once, when the program starts, and every use shares it, which is safe because nobody can change
the object (the kind's own code could still put another one in the slot):

```gaz
readonly kind Colour {
    fn _(pub string #name);
}

kind Palette {
    static #default = Colour("black");

    pub static fn ink(?string $name = null): Colour {
        return $name == null ? #default : Colour($name);
    }
}

echo Palette::ink().name;
echo Palette::ink("teal").name;
```

```output
black
teal
```

A static field holds the program's state between calls, so keep them few: a count, a cache, a
shared default. Anything a test would want to set up fresh is better passed in as an argument.

## Question
`kind Ticket` has `static #issued = 0;`, and each `Ticket::next()` adds one to it. After
`Ticket::next(); Ticket::next(); Ticket::next();`, what is `#issued`?

- [x] 3: there is one slot for the whole kind
- [ ] 1: each ticket has its own `#issued`
- [ ] 0: a static field can't change
