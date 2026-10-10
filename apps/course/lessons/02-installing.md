---
unit: Getting started
title: Installing gaz
---
Every release on the [releases page](https://github.com/panzer-planet/gazlang/releases) has a
ready-made `gaz` for three systems:

- `gaz-VERSION-linux-x86_64.tar.gz` for Linux on Intel or AMD
- `gaz-VERSION-macos-arm64.tar.gz` for a Mac with Apple silicon
- `gaz-VERSION-macos-x86_64.tar.gz` for an Intel Mac

Download yours, unpack it and put `gaz` somewhere on your `PATH`. For version 0.12.0 on an Apple
silicon Mac:

```bash
curl -LO https://github.com/panzer-planet/gazlang/releases/download/v0.12.0/gaz-0.12.0-macos-arm64.tar.gz
tar -xzf gaz-0.12.0-macos-arm64.tar.gz
sudo mv gaz-0.12.0-macos-arm64/gaz /usr/local/bin/
gaz --version
```

```output
gaz 0.12.0
```

Each release also has a `SHA256SUMS` file: compare its line for your download with what
`shasum -a 256` (or `sha256sum` on Linux) prints for it, to be sure the file is the one that was
published.

## What else you might need

HTTPS and SQLite work with nothing more. PostgreSQL needs its client library, libpq, which `gaz`
loads the first time a program opens a PostgreSQL database: `brew install libpq` on a Mac, or
`apt install libpq5` on Debian and Ubuntu. A program that never opens one never needs it.

## Building from source

Building needs only a C compiler and make, plus OpenSSL for HTTPS (`apt install libssl-dev` or
`brew install openssl@3`):

```bash
git clone https://github.com/panzer-planet/gazlang.git
cd gazlang
make -C vm
bin/gaz --version
```

SQLite and PostgreSQL support are built in when their libraries are found, and any of the three
can be left out on purpose: `make -C vm TLS=0 SQLITE=0 PG=0`. A clone also gives you the
`examples/`, the editor grammars in `editors/` and the language server, which later lessons use.

## Question
What do you need, at the least, to build `gaz` from source?

- [ ] PHP and Composer
- [x] A C compiler and make
- [ ] An older `gaz` to compile the new one
