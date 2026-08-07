# Working on native-php-matrix

This library gives PHP a `Matrix` class with real operator overloading — `+`, `-`,
`*`, `/`, `**` and `==` are dispatched by the Zend Engine itself, not emulated in
userland. It does that by using [z-engine](https://github.com/lisachenko/z-engine)
to install `create_object`, `do_operation` and `compare` handlers directly onto the
`Matrix` class entry through PHP FFI. The matrix arithmetic underneath is ordinary,
readable PHP; the only unusual part is how the engine is convinced to call it.

## The one rule that is non-negotiable: PHP minor and z-engine line move together

`composer.json` requires **`php: ^8.4`**, and PHP 8.4 and 8.5 are supported **in
parallel** — each minor riding its own z-engine line. z-engine reads engine
structures (`zend_class_entry`, `zval`, `zend_object_handlers`) by byte offset, and
those offsets change on every PHP minor release. Running against the wrong minor
does not throw a nice exception; it reads and writes the wrong memory.

That is why z-engine is required as **`8.4.x-dev || 8.5.x-dev`**: Composer resolves
the line matching the running PHP (the `8.4` branch on PHP 8.4, `master` — aliased
`8.5.x-dev` — on PHP 8.5). `ZEngine\Core::init()` (called from `bootstrap.php`)
enforces the exact match and aborts with a clear message. **Never "fix" an
initialization failure by loosening the constraints past the minors z-engine has
definitions for, skipping `Core::init()`, or defeating the guard.** If the
environment's PHP does not satisfy `^8.4` (8.4 or 8.5), the environment is wrong —
say so and stop.

## Running tests

```bash
composer test
```

The suite is PHPUnit 12 driving `.phpt` files in `tests/Functional/`. Three INI
settings must hold in **both** the parent PHPUnit process and every `.phpt` child
process it spawns:

- `ffi.enable=1` — FFI cannot be turned on at runtime.
- `opcache.jit=off` — the JIT rewrites the very executor internals z-engine hooks.
- `error_reporting=E_ALL & ~E_DEPRECATED` — z-engine `dev-master` still declares
  implicitly nullable parameters (e.g. `ZEngine\Type\OpLine::__construct()`), which
  PHP 8.4 reports as a deprecation. PHPUnit's `.phpt` runner forces
  `display_errors=1`, so without this the dependency's deprecation is prepended to
  the captured output of **every** test and each `--EXPECT--` block fails on noise
  that has nothing to do with this library. Drop the suppression once z-engine
  declares those parameters `?Type`.

CI supplies the FFI and JIT pair as `ini-values` on the PHP setup step, and **every
`.phpt` file carries its own `--INI--` section** — all three lines — so the child
processes inherit nothing by luck. The deprecation suppression only ever matters in
the children, because those are the processes whose output is compared.

For a local one-off run without touching `php.ini`:

```bash
php -d ffi.enable=1 -d opcache.jit=off vendor/bin/phpunit
```

Single test:

```bash
php -d ffi.enable=1 -d opcache.jit=off vendor/bin/phpunit tests/Functional/testCanAddMatrices.phpt
```

> **A segfault or bus error is not a normal test failure.** It means a hook or an
> engine structure is being used incorrectly — an engine-level bug. Do not retry
> the run hoping it passes, do not mark the test skipped: report the crash with the
> exact command, PHP version, and the test that triggered it.

## Quality gates (all enforced in CI)

```bash
composer phpstan     # PHPStan at level max
composer cs:check    # coding standards, PER-CS2.0
composer cs:fix      # apply the fixes
```

`Matrix` is declared `@template-covariant T of int|float`. **Keep the generics
honest.** Arithmetic widens: dividing or exponentiating a `Matrix<int>` can produce
floats, so those results are `Matrix<int|float>`, never `Matrix<T>`. Do not annotate
a method as preserving `T` to make PHPStan quiet — if the maths does not preserve
the type, the signature must not claim it does.

## Anatomy of a `.phpt` test

Each file covers exactly one behaviour and is named `test<WhatItDoes>.phpt`:

```
--TEST--
Matrices can be added with "+" operator
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

$matrixA = new Matrix([[1, 2, 3]]);
$matrixB = new Matrix([[4, 5, 6]]);
$result  = $matrixA + $matrixB;
var_dump($result->toArray());
?>
--EXPECT--
array(1) {
  [0]=>
  array(3) {
    [0]=>
    int(5)
    [1]=>
    int(7)
    [2]=>
    int(9)
  }
}
```

Rules for a new test:

- `--INI--` is **mandatory, all three lines** — without them the child process has no
  FFI, runs the JIT over hooked internals, or drowns the expected output in a
  z-engine deprecation, and the test fails in a way that looks like a library bug.
- Include the autoloader with the relative path `__DIR__ . '/../../vendor/autoload.php'`;
  that is what triggers `bootstrap.php` and installs the handlers.
- Prefer `--EXPECT--` (exact match). Use `--EXPECTREGEX--` only when the output
  genuinely varies — uncaught-exception output, for example, contains file paths and
  stack traces.
- One behaviour per file. Failure cases (incompatible dimensions, unsupported
  operator combinations) get their own files.

## Repository map

```
src/Matrix.php            the entire library: the maths plus the __doOperation/__compare hooks
bootstrap.php             Core::init() then installExtensionHandlers() on Matrix — order matters;
                          runs automatically via Composer's "files" autoload
tests/Functional/*.phpt   the functional suite, one behaviour per file
phpunit.xml.dist          PHPUnit 12 config (suite points at tests/, suffix .phpt)
phpstan.dist.neon         static analysis config, level max
.php-cs-fixer.dist.php    coding standards config (PER-CS2.0)
.github/workflows/ci.yml  jobs: tests, static-analysis, coding-standards — PHP 8.4 and 8.5
```

`src/Matrix.php` is the whole library. There is no framework here to hide behind: a
change to a hook signature or to `bootstrap.php`'s ordering affects every operator
at once.

## Hook contracts

- `__doOperation(DoOperationHook $hook)` and `__compare(CompareValuesHook $hook)`
  are **static**. They do throw — `InvalidArgumentException` for a dimension
  mismatch, `LogicException` for an operand combination the class does not
  implement — but be precise about what that means for a caller: these hooks run
  inside an **FFI callback**, and PHP does not let an exception cross that
  boundary. The engine reports the exception and then aborts with
  `Fatal error: Throwing from FFI callbacks is not allowed`. The script dies with
  exit code 255; a userland `try`/`catch` around `$a + $b` **will not catch it**.
  This is why the incompatible-dimension tests use `--EXPECTREGEX--` and match the
  message inside fatal-error output rather than catching anything.
- Validation performed in the **constructor** is ordinary userland code and its
  `InvalidArgumentException` *is* catchable — that is the difference between
  `testFailsOnEmptyMatrix.phpt` (uses `try`/`catch`) and
  `testFailsOnAddingIncompatibleMatrices.phpt` (cannot). Prefer validating in the
  constructor where a failure mode can be detected there.
- Handlers that the engine calls in non-throwing contexts — `get_debug_info`,
  `get_iterator`, `cast_object` in some paths — **must not throw**. If a future
  feature adds one of those, it has to degrade gracefully instead.
- Operations return a **new** `Matrix`. Nothing mutates in place; `$a += $b` works
  because the engine rebinds the variable to the returned object.

## Conventions

[Conventional Commits](https://www.conventionalcommits.org/):

```
feat(matrix): support element-wise exponentiation by scalar
fix(bootstrap): install create_object handler before do_operation
test(tests): cover division by zero
ci: run the suite on PHP 8.4 and 8.5 with ffi.enable=1
docs: rewrite the README in the z-engine style
```

Scopes in use: `matrix`, `bootstrap`, `tests`, `ci`, `docs`.

Code style is **PER-CS2.0**, applied by php-cs-fixer. Run `composer cs:fix` before
proposing a change rather than hand-formatting.

## Dependency policy

- `lisachenko/z-engine` is required as **`8.4.x-dev || 8.5.x-dev`** — one dev line
  per supported PHP minor, resolved by Composer to match the running PHP. Those are
  development branches, so the root `composer.json` also carries
  `"minimum-stability": "dev"` with `"prefer-stable": true` — Composer resolves
  development stability only at the root level, so consumers need the same pair.
- PHP stays at `^8.4`, in lockstep with the set of z-engine lines this package
  tracks: a new PHP minor is added here only together with the z-engine line built
  for it, and never one without the other.
- When z-engine ships stable releases for the supported minors, the constraint and
  the root stability flags should be tightened in a single change.
