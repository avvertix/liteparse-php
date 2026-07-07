# Contributing to liteparse-php

Thank you for your interest in contributing! This document covers how to get set up, how to submit
changes, and the conventions we follow.

## Table of Contents

- [Prerequisites](#prerequisites)
- [Repository Structure](#repository-structure)
- [Development Setup](#development-setup)
- [How to Contribute](#how-to-contribute)
- [Development Guidelines](#development-guidelines)
- [Commit Message Guidelines](#commit-message-guidelines)

---

## Prerequisites

- PHP 8.3+ with `ext-ffi` enabled (`ffi.enable=On` in `php.ini`; the CLI SAPI defaults to on)
- Rust toolchain (latest stable)
- Composer

---

## Repository Structure

```
liteparse-php/
├── src/LiteParse/          # PHP source code (namespace root)
│   ├── Composer/           # Composer platform detection helpers
│   ├── Console/            # `liteparse-php install`/`update` CLI commands
│   └── Exception/          # Exception hierarchy
├── rust/                   # Rust cdylib (liteparse_php), wraps the upstream `liteparse` crate
│   └── src/
│       └── ffi/            # extern "C" FFI surface (parser, screenshot, search, complexity, ...)
├── tests/                  # PHPUnit tests
│   ├── Integration/        # Tests exercised against the compiled native library
│   ├── Unit/                # Pure PHP unit tests
│   └── fixtures/           # Sample PDFs used by the tests
├── examples/               # Runnable PHP examples (simple parsing, screenshots)
├── scripts/                # build.sh (cargo build + stage lib/) and copy-pdfium.sh
├── lib/                    # Compiled native libraries (platform-specific, gitignored)
└── include/                # Generated C header (liteparse_php.h, via cbindgen)
```

---

## Development Setup

```bash
# 1. Fork and clone
git clone https://github.com/avvertix/liteparse-php.git
cd liteparse-php

# 2. Install PHP dependencies
composer install

# 3. Build the Rust library and stage it into lib/
./scripts/build.sh release

# 4. Run tests to verify setup
composer test

# 5. Run static analysis
composer lint
```

---

## How to Contribute

### Reporting Bugs

Before opening an issue:

1. Check if it has already been reported.
2. Try to reproduce with the latest version.

Please include:

- Clear description and steps to reproduce
- Expected vs actual behaviour
- PHP version (`php -v`), platform, and liteparse-php version
- Error messages or stack traces

### Suggesting Features

Open an issue explaining the use case and why it would be valuable. If you have an implementation
idea, sketch it out — that makes the discussion faster.

### Pull Requests

1. Create a branch from `main`:
   ```bash
   git checkout -b feature/my-feature
   # or
   git checkout -b fix/bug-description
   ```

2. Make your changes following the guidelines below.

3. Verify everything passes:
   ```bash
   composer test
   composer lint
   ```

4. Open a PR with a clear title, description, and reference to any related issues.

---

## Development Guidelines

### PHP

- Follow PSR-12 coding standards (enforced via `composer format`, using Laravel Pint)
- Use type hints for all parameters and return types
- Keep methods focused (single responsibility)
- 4-space indentation, 120-character soft line limit

### Rust

- Follow standard Rust idioms
- Add `# Safety` documentation for every `unsafe` block
- Document all `pub extern "C"` FFI functions in `rust/src/ffi/`
- Prefer safe Rust; reach for `unsafe` only at the C boundary
- `cbindgen` regenerates `include/liteparse_php.h` automatically via `build.rs` — don't hand-edit it
- When adding a new FFI function, add the corresponding `@method` annotation to `LiteParseFfi` and
  wrap it in a PHP class

### Tests

All PRs must include tests.

```bash
composer test                              # Run all tests
./vendor/bin/phpunit --filter testName      # Single test
```

Integration tests run against the compiled `liteparse_php` native library, so run
`./scripts/build.sh` (or `./scripts/build.sh release`) before `composer test` if you changed Rust code.

---

## Commit Message Guidelines

We use [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<scope>): <subject>

[optional body]

[optional footer]
```

**Types:** `feat` · `fix` · `docs` · `style` · `refactor` · `perf` · `test` · `chore`

**Scopes:** `php` · `rust` · `ffi` · `docs` · `tests`

Examples:

```
feat(php): add password support to Config
fix(rust): prevent panic on empty PDF input
docs: add FFI setup instructions for Windows
test(php): cover isComplexFile() OCR heuristics
```

## Questions?

- Open an [Issue](https://github.com/avvertix/liteparse-php/issues) for questions
- Check the [README](./README.md) for usage help

Thank you for contributing to liteparse-php!
