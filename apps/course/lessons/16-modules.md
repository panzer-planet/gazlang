---
unit: Structuring programs
title: Modules and projects
---
A program of more than one file is a project: a `gaz.json` at its root, and files that import
each other.

```text
shop/
  gaz.json        {"name": "shop"}
  main.gaz
  prices.gaz
  views/cart.gazml
```

Each file is a module. A file that is imported only declares things (functions, kinds, constants,
imports), so importing one never runs anything. It usually starts with a namespace:

```gaz
// prices.gaz
namespace prices;

const VAT = 0.15;

pub fn with_vat(float $amount): float {
    return round($amount * (1 + VAT), 2);
}
```

The file you run imports it and reaches its names through the namespace:

```gaz nocheck
// main.gaz
import "prices.gaz";

echo prices::with_vat(100);
```

```bash
gaz shop/main.gaz
```

```output
115.0
```

## The rules

- `import "std/json.gaz";` is the standard library, built into `gaz`. `import "./x.gaz";` is next
  to this file. Any other path is from the project root.
- `import` lines come first, after a `namespace` line if there is one.
- A name is private to its namespace unless it is `pub`. `VAT` above can't be reached from
  `main.gaz`; `with_vat` can.
- A file sees what it imports, and no further: a name declared in a file you didn't import is an
  error that names the import to add.
- `import "std/chars.gaz" use is_digit;` brings names in without their namespace.
- A project runs from anywhere: `gaz shop/main.gaz` and `cd shop && gaz main.gaz` are the same.

Namespaces are why `json::encode` and `prices::with_vat` can never collide, and why a file's first
lines tell you everything it depends on.

## Question
What may a file contain if another file imports it?

- [x] Only declarations: `namespace`, `import`, `fn`, `kind`, `const` and the like
- [ ] Anything; its statements run when it is imported
- [ ] Only functions
