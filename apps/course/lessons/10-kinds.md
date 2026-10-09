---
unit: Structuring programs
title: Objects with kinds
---
A kind is a kind of thing: it declares its fields and methods, and constructing it is a call by
its name. There is no `new` and no `class`.

```gaz
kind Account {
    pub float #balance = 0;

    fn _(pub string #owner) {}

    pub fn deposit(float $amount) {
        #balance += $amount;
    }

    pub fn to_string() {
        return "{#owner} has {#balance}";
    }
}

$account = Account("Ada");
$account.deposit(10);
echo $account;
echo $account.owner;
```

```output
Ada has 10.0
Ada
```

- `_` is the constructor. A parameter written `#owner` declares the field and sets it from the
  argument in one go.
- Inside the kind, `#name` is this object's field or method, and `#` alone is the object.
  Outside it, `.` reaches a member: `$account.owner`, `$account.deposit(10)`.
- A member is private to its kind unless marked `pub` (anyone's) or `kin` (its kind's and the
  kinds that extend it).
- `to_string()` is what `echo` and `..` print.

## Objects are handles

Lists and maps are copied; objects are not. Assigning an object gives another name for the same
one, so a change through either is seen through both:

```gaz
kind Counter {
    pub #count = 0;
}

$a = Counter();
$b = $a;
$b.count++;
echo $a.count;
echo $a == $b;
```

```output
1
true
```

## Extending a kind

A kind can extend one other. An `abstract` kind can't be made itself, and its `abstract` methods
must be written by the kinds below it. `##` reaches the parent's version of a method.

```gaz
abstract kind Shape {
    pub abstract fn area();

    pub fn describe() {
        return kind_name(#) .. " with area " .. #area();
    }
}

kind Square extends Shape {
    fn _(pub #side) {}

    pub fn area() {
        return #side * #side;
    }
}

echo Square(3).describe();
echo is_a(Square(1), Shape);
```

```output
Square with area 9
true
```

## Question
`$b = $a;` where `$a` holds an object, then `$b.count++`. What happens to `$a.count`?

- [x] It goes up too: both names hold the same object
- [ ] Nothing: `$b` is a copy
- [ ] It's an error to change an object through a second name
