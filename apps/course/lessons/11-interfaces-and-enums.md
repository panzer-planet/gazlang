---
unit: Structuring programs
title: Interfaces and enums
---
An interface names methods that unrelated kinds can share. A kind says it `implements` one, and
the program is checked as it is read: a kind that claims an interface must have every method it
names, `pub` and with the same types.

```gaz
interface Shape {
    fn area(): float;
}

kind Circle implements Shape {
    fn _(float #radius) {}

    pub fn area(): float {
        return 3.0 * #radius * #radius;
    }
}

kind Rectangle implements Shape {
    fn _(float #width, float #height) {}

    pub fn area(): float {
        return #width * #height;
    }
}

fn total_area(list $shapes): float {
    return sum(map($shapes, $shape -> $shape.area()));
}

echo total_area([Circle(1), Rectangle(2, 3)]);
echo is_a(Circle(2), Shape);
```

```output
9.0
true
```

An interface is also a type: a parameter written `Shape $shape` takes any kind that implements it,
and nothing else.

## Enums

An enum is a closed set of named values. Its cases are its only objects, so a misspelt case is an
error when the program is read, where a misspelt string would quietly never match.

```gaz
enum Status: string {
    Open = "open";
    Done = "done";

    pub fn label(): string {
        return match (#) {
            Status::Open => "To do",
            Status::Done => "Finished",
        };
    }
}

$status = Status::from("done", Status::Open);
echo $status.label();
echo $status.value;
echo Status::from("lost", Status::Open);
echo Status::cases();
```

```output
Finished
done
Status::Open
[Status::Open, Status::Done]
```

- `enum Status: string` gives every case a value; an enum can have `int` values, or none.
- `from()` finds the case with a value, or gives its second argument when none has it: the way to
  read a value from a form or a database.
- An enum can have methods and implement interfaces, but no fields.

## final

`final kind` means no kind may extend it, and `final fn` that no child may override that method:
a way to protect an invariant from code written later.

## Question
What does `Status::from("lost", Status::Open)` give, when no case has the value `"lost"`?

- [x] `Status::Open`, the default it was given
- [ ] `null`
- [ ] An error naming the value
