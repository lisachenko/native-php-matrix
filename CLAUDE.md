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
composer bench       # not a gate: compares the drivers, see bench/benchmark.php
```

PHPStan analyses `src`, `bench` and `bootstrap.php`. The only ignored errors are the
FFI ones — methods that exist solely after `FFI::cdef()` has parsed the inline C, the
pseudo-property on scalar handles, index access on `FFI\CData`, and the operators of
the benchmark, which no analyser can know the engine dispatches. Each entry in
`phpstan.dist.neon` is scoped to a path, carries an identifier and a comment; there is
no baseline. Keep it that way — new ignores need the same justification.

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

### Backend tests

- Pin the driver with an `--ENV--` section (`NATIVE_PHP_MATRIX_BACKEND=blas`), which
  is merged into the child's environment, not substituted for it — the CI-wide
  `NATIVE_PHP_MATRIX_CL_DEVICE` still reaches the test.
- Guard a driver-specific test with the shared probe:
  `--SKIPIF--` including `include/skipif_blas.inc` or `include/skipif_clblast.inc`.
  Those ask `Backends::available()`, which loads the library and runs a real
  operation, so a skip can never disagree with the test. PHPUnit prints the skip
  message minus two characters, hence the `skip - ` prefix.
- `.inc` files under `tests/Functional/include/` hold the shared probes and the
  backend stubs. The suite collects `.phpt` only, so they are never run as tests, but
  php-cs-fixer does check them.
- Use **integral-valued float fixtures and small dimensions**. Every partial sum then
  stays exactly representable in double precision, so a driver that accumulates in a
  different order still has to produce an identical result — which is what the
  `*MatchesPhpBackendResults` tests assert. For division, use power-of-two divisors:
  BLAS scales by the reciprocal, and only then is `x * (1/s)` exactly `x / s`.
- **A test's expectation may never depend on the ambient environment.** A `.phpt`
  file that asserts the *default* selection pins `NATIVE_PHP_MATRIX_BACKEND=` (empty,
  which means "unset") in its `--ENV--`, so it keeps asserting the default even when
  the whole suite is run with a backend pinned. Conversely, the classic operator tests
  deliberately keep asserting integer results under the default routing: running the
  entire suite with `NATIVE_PHP_MATRIX_BACKEND=blas` or `=clblast` makes them fail,
  and that is the float-only rule working as documented, not a regression. Those two
  drivers are exercised by their own pinned tests, which is also why the `gpu-path` CI
  job names the CLBlast test files instead of running everything.
- `failOnSkipped="true"` is set, so a skip fails the suite. That is deliberate: CI
  installs every acceleration library, so a skip there means a broken image. Locally,
  without the libraries, run
  `php -d ffi.enable=1 -d opcache.jit=off vendor/bin/phpunit --do-not-fail-on-skipped`.

The acceleration libraries CI installs, and what a local machine needs for the full
suite:

```bash
sudo apt-get install -y libopenblas0 libclblast1 ocl-icd-libopencl1 pocl-opencl-icd
```

PoCL provides an OpenCL device on the CPU, which is how the GPU code path is covered
on runners that have no GPU; the `gpu-path` job pins
`NATIVE_PHP_MATRIX_BACKEND=clblast` and `NATIVE_PHP_MATRIX_CL_DEVICE=cpu`.

## Repository map

```
src/Matrix.php               validation, dimensions, the __doOperation/__compare hooks; the arithmetic
                             itself is delegated to a backend driver
src/Backend/                 the interchangeable drivers and the registry that picks one
tests/Functional/*.phpt      the functional suite, one behaviour per file
tests/Functional/include/    shared SKIPIF probes and backend stubs (.inc — never collected as tests)
bench/benchmark.php          driver comparison CLI, "composer bench"
bootstrap.php                Core::init(), installExtensionHandlers() on Matrix, then
                             Backends::bootFromEnvironment() — order matters; runs automatically via
                             Composer's "files" autoload
phpunit.xml.dist             PHPUnit 12 config (suite points at tests/, suffix .phpt)
phpstan.dist.neon            static analysis config, level max
.php-cs-fixer.dist.php       coding standards config (PER-CS2.0)
.github/workflows/ci.yml     jobs: tests, gpu-path, static-analysis, coding-standards — PHP 8.4 and 8.5
```

`src/Matrix.php` is still the centre of the library. There is no framework here to
hide behind: a change to a hook signature or to `bootstrap.php`'s ordering affects
every operator at once.

## Backend architecture

`Matrix` no longer does the arithmetic itself. It validates, checks dimensions, and
asks `Backends::resolveFor()` which driver should compute — drivers receive plain
arrays with the dimensions alongside them and return plain arrays.

```
src/Backend/BackendInterface.php             the driver contract: six operations, plus isAvailable()
src/Backend/Backends.php                     registry, selection and the auto-routing policy
src/Backend/PhpBackend.php                   the original interpreted loops, verbatim
src/Backend/BlasBackend.php                  OpenBLAS over FFI (CPU)
src/Backend/ClblastBackend.php               CLBlast over OpenCL (GPU, or CPU via PoCL)
src/Backend/AcceleratedBackendTrait.php      packing, unpacking and the pow loop shared by both
src/Backend/FallbackBackend.php              decorator: degrade to another driver instead of failing
src/Backend/BackendNotAvailableException.php catchable, thrown at selection time only
```

Three rules govern this part of the codebase, and none of them is negotiable:

- **Hook safety.** Anything reachable from an operation runs inside an FFI callback,
  where a thrown exception becomes `Fatal error: Throwing from FFI callbacks is not
  allowed`. Drivers therefore report their unusability from `isAvailable()`, which
  swallows its own failures, and selection is validated eagerly in userland —
  `Backends::use()` and `bootFromEnvironment()`. Under `auto`, a driver that fails at
  operation time is caught by `FallbackBackend` and the result is recomputed in pure
  PHP. Catching *inside* a hook is fine; only crossing the boundary is fatal.
- **Accelerated drivers are float-only.** They compute in double precision, so they
  cast integer cells and return floats. Never "fix" that by rounding results back to
  integers.
- **Auto-routing keeps integers on pure PHP.** `auto` may use an accelerated CPU
  driver when an operand contains a float; an all-integer operation always takes the
  `php` path, so its results stay bit-identical to what this library returned before
  drivers existed. A GPU is never chosen automatically.

An availability probe performs a real 1×1 operation, so it cannot disagree with what
an operation would do a moment later. Probes are cached per process.

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
feat(backend): add OpenBLAS driver with dgemm/daxpy/dscal over FFI
fix(bootstrap): install create_object handler before do_operation
test(tests): cover division by zero
ci: run the suite on PHP 8.4 and 8.5 with ffi.enable=1
docs: rewrite the README in the z-engine style
```

Scopes in use: `matrix`, `backend`, `bootstrap`, `tests`, `ci`, `docs`.

Code style is **PER-CS2.0**, applied by php-cs-fixer. Run `composer cs:fix` before
proposing a change rather than hand-formatting.

**Global functions and constants are never imported.** Call `count()`, `sprintf()`,
`is_int()` and friends unqualified, and write `PHP_EOL` or `ARRAY_FILTER_USE_KEY`
as they are — no `use function` or `use const` lines anywhere. Only classes,
interfaces, traits and enums get a `use` statement. The import lists were pure
noise, and the fixer neither adds nor removes these imports, so the convention is
stable under `composer cs:fix`.

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
- **System libraries are never Composer requirements.** OpenBLAS, CLBlast and an
  OpenCL runtime are optional, discovered at runtime by the drivers that need them,
  and listed under `suggest`. The package must install and its suite must pass — with
  `--do-not-fail-on-skipped` — on a machine that has none of them; the pure-PHP driver
  is always available. Add a new accelerated driver the same way: lazy load, probe,
  degrade.
