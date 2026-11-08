---
unit: Structuring programs
title: Objects with kinds
---
A kind is a kind of thing: it declares its fields and methods, and constructing it is a call by
its name. There is no `new` and no `class`.

```gaz
kind Account {
    pub float #balance = 0;

    fn _(pub string #owner);

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

## When there might be no object

A field or a variable that may hold `null` instead of an object needs a check before `.`, since
`.` on `null` is an error. `?.` is that check: `$customer.address?.city` is `null` when the address
is, and so is everything after it in the chain, which never runs. `??` then gives the fallback:

```gaz
kind Address {
    fn _(pub string #city);
}

kind Customer {
    fn _(pub string #name, pub ?Address #address = null);
}

$known = Customer("Ada", Address("Cape Town"));
$walk_in = Customer("Grace");
$nobody = null;

echo $known.address?.city;
echo $walk_in.address?.city ?? "no address";
echo $nobody?.address.city ?? "no customer";
```

```output
Cape Town
no address
no customer
```

Only `null` is skipped. A member that doesn't exist is still an error through `?.`, so a typo
isn't hidden as a quiet `null`.

## Objects nobody can change

Sharing is what you want for a connection, a router or a screen, but not for a value such as an
amount of money: nobody should be able to change your price behind your back. Mark the kind
`readonly`, and its fields are set by its constructor and by nothing else, ever:

```gaz
readonly kind Money {
    fn _(pub int #cents, pub string #currency);

    pub fn plus(Money $other): Money {
        if ($other.currency != #currency) {
            throw "Can't add {$other.currency} to {#currency}";
        }

        return # with (cents: #cents + $other.cents);
    }

    pub fn to_string() {
        return "{#currency} " .. #cents / 100;
    }
}

$price = Money(1500, "ZAR");
$total = $price.plus(Money(250, "ZAR"));
$free = $price with (cents: 0);
echo $price;
echo $total;
echo $free;

try {
    $total.cents = 0;
} catch (Error $e) {
    echo $e.message;
}
```

```output
ZAR 15.0
ZAR 17.5
ZAR 0.0
Money #cents is read-only: only Money's constructor sets it
```

A method of a read-only kind changes nothing; it gives back a new object, as `plus()` does. Such
an object can be shared as freely as a number. `with` makes that new object, the old one with the
fields you name changed: the next lesson is about it. A single field can be read-only too, in a
kind whose other fields can change: `pub readonly int #id;` is an id anyone can read and nobody can alter
once the object is made.

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
    fn _(pub #side);

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
