<div align="center">

# 🧮 Native PHP Matrix

### Real operator overloading for PHP — in pure PHP.

**Native PHP Matrix** gives PHP a `Matrix` type with genuine arithmetic: `+`, `-`, `*`, `/`, `**` and `==` all work on matrices the way they work on numbers. There is no C code, no PECL package, no compiler — just [Z-Engine](https://github.com/lisachenko/z-engine) hooking the Zend Engine's own `do_operation` and `compare` handlers through FFI.

[![CI](https://img.shields.io/github/actions/workflow/status/lisachenko/native-php-matrix/ci.yml?branch=master&label=CI)](https://github.com/lisachenko/native-php-matrix/actions/workflows/ci.yml)
[![GitHub release](https://img.shields.io/github/release/lisachenko/native-php-matrix.svg)](https://github.com/lisachenko/native-php-matrix/releases/latest)
[![PHP Version](https://img.shields.io/badge/php-8.4-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/packagist/l/lisachenko/native-php-matrix.svg)](https://packagist.org/packages/lisachenko/native-php-matrix)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](https://phpstan.org/)

</div>

---

> **⚠️ Experimental — educational first, production second.** This library installs custom object handlers into a running Zend Engine. It is a demonstration of what userland PHP can reach, and it inherits every constraint of the machinery underneath it. Pin your PHP version, keep it out of production until there are stable tags on both this package and Z-Engine, and treat a crash as an engine-level bug report rather than a flaky test.

## The first PHP "extension" written without C

For a long time operator overloading was the exclusive privilege of compiled extensions: write C, learn the `zend_object_handlers` struct, build a `.so`, ship a PECL release. This library is the first ever *userland* PHP extension that implements true operator overloading — and it does it entirely in PHP.

The trick is [lisachenko/z-engine](https://github.com/lisachenko/z-engine), which loads version-exact FFI definitions of the engine's C structures and lets PHP write into them. `Matrix` installs its own `do_operation` and `compare` handlers on its own class entry, so when the VM evaluates `$a * $b` and sees an object operand, it calls straight back into PHP code. The operators are not simulated, not parsed, not wrapped in a fluent API — the engine really dispatches them.

## What you get

| | Operation | Example |
|---|---|---|
| ➕ | **Addition** — element-wise, dimensions must match | `$a + $b` |
| ➖ | **Subtraction** — element-wise, dimensions must match | `$a - $b` |
| ✖️ | **Matrix multiplication** — columns of the left must match rows of the right | `$a * $b` |
| 🔢 | **Scalar multiplication** — in both directions | `$a * 2` and `2 * $a` |
| ➗ | **Scalar division** | `$a / 2` |
| 🔺 | **Scalar exponentiation** — element-wise power | `$a ** 2` |
| 🟰 | **Equality** — strict element-wise comparison | `$a == $b`, `$a != $b` |
| 🛡️ | **Type-safe by design** — PHPStan generics `Matrix<int>` / `Matrix<float>`, checked at level max | `Matrix<int|float>` |

Every operation returns a **new** `Matrix`; the class is `final` and its state is `readonly`, so nothing is ever mutated in place. The constructor validates that you passed a rectangular list of rows holding only `int`/`float` cells and raises a catchable `InvalidArgumentException` when it does not.

Operator-level failures are a different story, and it is worth being blunt about it: a dimension mismatch (`InvalidArgumentException`) or an unimplemented operand combination such as `$a - 2` (`LogicException`) is raised *inside an FFI callback*, and PHP 8.4 does not allow an exception to cross that boundary. The engine prints the exception and then halts with `Fatal error: Throwing from FFI callbacks is not allowed`. You cannot `try`/`catch` it — check your dimensions before you multiply.

Generics are honest about arithmetic: `Matrix<int>` divided by `2` is a `Matrix<int|float>`, because PHP's `/` widens. Nothing pretends to preserve `T` where the maths does not.

## How it works

Three moving parts, in order:

1. **`bootstrap.php`** is registered in Composer's `files` autoload, so it runs the moment you `require vendor/autoload.php`. It calls `ZEngine\Core::init()`, which validates that the FFI struct definitions match the exact PHP build you are running.
2. It then calls `installExtensionHandlers()` on `ZEngine\Reflection\ReflectionClass` for `Matrix`, wiring the class's `create_object`, `do_operation` and `compare` slots to the engine trampolines. Order matters — `create_object` allocates the memory the other handlers live in.
3. **`Matrix::__doOperation()`** and **`Matrix::__compare()`** are static hooks. The engine hands them a `DoOperationHook` / `CompareValuesHook` describing the opcode and both operands; they dispatch to ordinary, boring, pure-PHP methods (`sum()`, `subtract()`, `multiply()`, `multiplyByScalar()`, `divideByScalar()`, `powByScalar()`, `equals()`).

The maths is plain PHP. The magic is only in getting the engine to call it.

## Requirements

- **PHP `~8.4.0`** — an exact minor, not a floor. Z-Engine reads engine structures by byte offset and those offsets change on every PHP minor release; `Core::init()` refuses to boot on a mismatch rather than corrupting memory.
- **`ext-ffi` enabled**, with `ffi.enable=1` for CLI usage.
- **x64, non-thread-safe (NTS)** build — the same platform limitations as [Z-Engine](https://github.com/lisachenko/z-engine#requirements--support-matrix).
- The **matching Z-Engine minor branch**. This package tracks the PHP 8.4 line; mixing a Z-Engine built for another minor is not a configuration choice, it is undefined behaviour.

## Installation

```bash
composer require lisachenko/z-engine:dev-master lisachenko/native-php-matrix:dev-master
```

Z-Engine does not yet have a stable tag with PHP 8.4 support, so both packages are consumed from `dev-master`. Composer only resolves development stability at the **root** level, which is why the requirement has to be written into your own project rather than inherited — your `composer.json` needs:

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

Once a stable 8.4-capable Z-Engine release exists, both constraints collapse back to ordinary version ranges.

No initialization call is needed: `bootstrap.php` ships in the package's `files` autoload and sets everything up behind `require vendor/autoload.php`.

## Usage

### The classic

```php
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

require __DIR__ . '/vendor/autoload.php';

$first  = new Matrix([[10, 20, 30]]);
$second = new Matrix([[2, 4, 6]]);

$value = $first * 2 + $second; // Matrix([[22, 44, 66]])
```

Operator precedence is the engine's own, so `*` binds tighter than `+` exactly as you would expect from numbers.

### Matrix by matrix

```php
$a = new Matrix([[1, 2, 3]]);
$b = new Matrix([[4], [5], [6]]);

$product = $a * $b;
var_dump($product->toArray()); // [[32]]  — a 1×3 times a 3×1 gives a 1×1
```

### Powers and division

```php
$m = new Matrix([[1, 2, 3]]);

var_dump(($m ** 2)->toArray()); // [[1, 4, 9]]   — element-wise exponentiation

$even = new Matrix([[2, 4, 6]]);
var_dump(($even / 2)->toArray()); // [[1, 2, 3]]
```

### Comparison

```php
$a = new Matrix([[1, 2], [3, 4]]);
$b = new Matrix([[1, 2], [3, 4]]);
$c = new Matrix([[1, 2], [3, 5]]);

var_dump($a == $b); // bool(true)
var_dump($a == $c); // bool(false)
var_dump($a != $c); // bool(true)
```

Equality is element-wise and strict. Ordering operators (`<`, `>`) are deliberately not meaningful for matrices — the `compare` hook reports "unordered" rather than inventing a total order.

### Beyond operators

The class is a normal PHP object too:

```php
$m = new Matrix([[1, 2], [3, 4]]);

$m->getRows();    // 2
$m->getColumns(); // 2
$m->isSquare();   // true
$m->toArray();    // [[1, 2], [3, 4]]
```

## Roadmap

Tracked as [GitHub issues](https://github.com/lisachenko/native-php-matrix/issues) — contributions welcome:

- **Array-style row access** — `$matrix[0]` and `$matrix[0][1]` via the `read_dimension` handler
- **`count($matrix)`** — row count through `Countable`, installed at the engine level
- **`foreach` iteration** — row-by-row traversal via the `get_iterator` handler
- **Scalar casts** — `(string)` and `(float)` behaviour through `cast_object`
- **Friendly `var_dump()`** — a `get_debug_info` handler that prints the matrix instead of its internals
- **FFI BLAS backend** — hand multiplication off to a real BLAS library for performance, keeping the pure-PHP path as the fallback

## Contributing

The repository ships an agent/contributor contract in **[CLAUDE.md](CLAUDE.md)** — read it before changing anything, especially the section on why the PHP version is pinned.

```bash
composer test        # PHPUnit 12, .phpt functional suite
composer phpstan     # static analysis at level max
composer cs:check    # coding standards (PER-CS2.0); composer cs:fix to apply
```

## License

Released under the [MIT License](LICENSE).
