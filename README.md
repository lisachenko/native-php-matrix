<div align="center">

# 🧮 Native PHP Matrix

### Real operator overloading for PHP — in pure PHP.

**Native PHP Matrix** gives PHP a `Matrix` type with genuine arithmetic: `+`, `-`, `*`, `/`, `**` and `==` all work on matrices the way they work on numbers. There is no C code, no PECL package, no compiler — just [Z-Engine](https://github.com/lisachenko/z-engine) hooking the Zend Engine's own `do_operation` and `compare` handlers through FFI.

[![CI](https://img.shields.io/github/actions/workflow/status/lisachenko/native-php-matrix/ci.yml?branch=master&label=CI)](https://github.com/lisachenko/native-php-matrix/actions/workflows/ci.yml)
[![GitHub release](https://img.shields.io/github/release/lisachenko/native-php-matrix.svg)](https://github.com/lisachenko/native-php-matrix/releases/latest)
[![PHP Version](https://img.shields.io/badge/php-8.4%20%7C%208.5%20%7C%208.6-8892BF.svg)](https://php.net/)
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
| 🧬 | **float64 storage** — a native `double[]` for the accelerated drivers, PHP float arrays for the interpreter | `new Matrix([[1, 2]])` |

Every operation returns a **new** `Matrix`; the class is `final` and nothing is ever mutated in place. The constructor validates that you passed a rectangular list of rows holding only `int`/`float` cells and raises a catchable `InvalidArgumentException` when it does not.

Operator-level failures are a different story, and it is worth being blunt about it: a dimension mismatch (`InvalidArgumentException`) or an unimplemented operand combination such as `$a - 2` (`LogicException`) is raised *inside an FFI callback*, and PHP does not allow an exception to cross that boundary. The engine prints the exception and then halts with `Fatal error: Throwing from FFI callbacks is not allowed`. You cannot `try`/`catch` it — check your dimensions before you multiply.

### Cells are float64

Every cell is a double, whatever you passed in and whichever driver computes. *Where* those doubles live follows the driver:

- under an accelerated driver a `Matrix` owns one contiguous, row-major `double[rows * columns]` allocation — the exact shape a BLAS kernel or an OpenCL buffer wants — so an operator hands its operands to the driver as raw pointers, with nothing packed on the way in and nothing unpacked on the way out;
- under the pure-PHP driver it keeps a list of rows of PHP floats, which the interpreter walks two to three times faster than it walks `FFI\CData`.

The form is chosen once, by the constructor, from the driver active at that moment. A matrix built before a switch to another driver still works with it: its cells are converted once for that operation, in bulk, and the result is stored in the form of the driver that computed it.

Integers are accepted as input and stored as the doubles they convert to, which is what `numpy.array([[1, 2]])` does when it reports `dtype=float64`:

```php
$m = new Matrix([[1, 2], [3, 4]]);
$m->toArray();                         // [[1.0, 2.0], [3.0, 4.0]] — ints in, float64 out
echo $m;                               // [1, 2] / [3, 4] — integral floats still print without decimals
```

So there is one cell type and one set of results, whichever driver computed them and whichever form holds them. Equality is bit-exact in every form: a `memcmp` over two buffers, the packed doubles of each row for two arrays.

## ⚡ Acceleration

The operators are the interesting part; the arithmetic underneath them is now interchangeable. `Matrix` asks a registry which **driver** should carry out an operation and hands it the operands in the storage that driver declares — validation, dimensions and object identity never leave the class. Three drivers ship with the package:

| Driver | Runs on | Uses | Notes |
|---|---|---|---|
| `php` | the interpreter | the original `foreach` loops over PHP float arrays | Always available, needs no shared library |
| `blas` | CPU | OpenBLAS `cblas_dgemm` / `cblas_daxpy` / `cblas_dscal` over FFI | Double precision, needs the OpenBLAS shared library |
| `clblast` | GPU | [CLBlast](https://github.com/CNugteren/CLBlast) on OpenCL | NVIDIA, AMD, Intel — including laptop iGPUs — or the CPU through PoCL |

Same interface for everyone else: `cuBLAS`, a `ggml` driver for the Vulkan route, and Metal are all a `BufferBackendInterface` implementation away — or an `ArrayBackendInterface` one, for a driver that computes on PHP arrays. **PRs welcome**.

### Choosing a driver

```bash
NATIVE_PHP_MATRIX_BACKEND=blas php your-script.php      # php | blas | clblast | auto (default)
NATIVE_PHP_MATRIX_CL_DEVICE=cpu php your-script.php     # gpu (default) | cpu | all — clblast only
```

```php
use Lisachenko\NativePhpMatrix\Backend\Backends;
use Lisachenko\NativePhpMatrix\Backend\Driver;

Backends::available();                 // ['php', 'blas'] — probed, not guessed
Backends::use(Driver::Blas);           // InvalidArgumentException / BackendNotAvailableException, both catchable
Backends::active();                    // Driver::Blas
Backends::register('cublas', static fn (): BackendInterface => new CuBlasBackend());
Backends::use('cublas');               // third-party drivers are named by the string they registered under
```

The drivers this package ships are a `Driver` enum, so they cannot be misspelled; a driver you register yourself keeps the arbitrary string you chose, and every method that names a driver accepts either.

Selection is validated **eagerly**, in userland — an unknown or unusable driver throws where you can still catch it, instead of surfacing as a fatal error from inside an operator hook later. Availability is not a guess either: probing loads the library and runs a real 1×1 multiplication with it.

### Installing the libraries

```bash
# Debian / Ubuntu
sudo apt-get install libopenblas0                                     # blas
sudo apt-get install libclblast1 ocl-icd-libopencl1                   # clblast, plus your vendor's ICD
sudo apt-get install pocl-opencl-icd                                  # ...or PoCL, an OpenCL runtime on the CPU

# macOS
brew install openblas clblast
```

### The rule worth knowing

**`auto` is deliberately boring.** It picks OpenBLAS whenever OpenBLAS is loadable, for *every* operation, on matrices stored as native buffers, and falls back to the pure-PHP driver on PHP arrays when it is not. It never selects a GPU on your behalf — moving data across a bus is a decision, not a default. If an accelerated driver fails mid-operation, `auto` recomputes in pure PHP rather than taking down the request.

That rule used to have two exceptions. Both are gone: there are no integer semantics left to protect, and no marshalling cost that could make an element-wise operation cheaper in the interpreter.

For web SAPIs, set `OPENBLAS_NUM_THREADS=1`: an OpenBLAS thread pool per PHP-FPM worker is rarely what you want.

### Why acceleration pays off everywhere now

It did not always. When a matrix was a PHP array, every operation had to copy its cells into a buffer and read the result back, and that overhead was proportional to the number of cells while the gain was proportional to the work. Multiplication does O(n³) work over O(n²) cells and could absorb it; element-wise operations do O(n²) work over O(n²) cells and could not, so `+`, `-` and scaling were genuinely *slower* accelerated than interpreted.

Storing cells in the buffer removes that overhead rather than amortising it. `cblas_daxpy` is now called on the memory the matrices already occupy, so element-wise operations are simply a kernel call and win too — see the numbers below.

The same reasoning, applied the other way round, is why the pure-PHP driver keeps arrays. A buffer is the right storage for a kernel and the wrong one for the interpreter: every `FFI\CData` cell access goes through FFI's type machinery, which made the interpreted loops two to three times slower when they had to walk buffers. So storage follows the driver, and each one computes on the form it reads fastest. The only conversion left is the one a driver switch makes unavoidable, for the matrices built before it.

## 🤖 Built for the AI/ML era

> *The syntax of the paper, the speed of the metal, in the language of the web.*

A neural network layer is one line of linear algebra, and with real operators it is one line of PHP:

```php
$logits = $input * $weights + $bias;   // dgemm on your CPU or GPU, dispatched by the Zend Engine
```

Inference is dominated by exactly this product. So is a semantic search that scores an embedding against a matrix of documents, so is re-ranking in a RAG pipeline, so is a recommender scoring a user vector against a catalogue. All of them are matrix multiplications — the operation this library hands to OpenBLAS or to your GPU.

That matters for PHP specifically. PHP still runs a large share of the web — WordPress alone is around 43% of it — and until now anything resembling machine learning meant a Python sidecar, a paid API, or shipping your users' data to somebody else's inference endpoint. This runs **in-process**, in the language the application is already written in.

The ecosystem agrees on the route: [Rindow Math Matrix](https://github.com/rindow/rindow-math-matrix) reaches OpenBLAS and CLBlast the same way, [TransformersPHP](https://github.com/CodeWithKyrian/transformers-php) runs ONNX models, and [RubixML/Tensor](https://github.com/RubixML/Tensor) is a compiled extension. What none of them have is the operators themselves: here `$a * $b` **is** the multiplication, dispatched by the engine, not a method call dressed up as one. And because the GPU path is OpenCL rather than CUDA, it reaches the integrated GPU in a laptop as readily as a datacentre card.

### Measured numbers

Matrix multiplication — the operation inference actually spends its time in:

| Operation | Size | `php` | `blas` | `clblast` | `blas` speed-up | `clblast` speed-up |
| --- | --- | --- | --- | --- | --- | --- |
| Multiplication `$a * $b` | 64×64 | 6.42 ms (0.1 GFLOP/s) | 0.04 ms (12.6 GFLOP/s) | 0.46 ms (1.1 GFLOP/s) | ×154.2 | ×13.9 |
| Multiplication `$a * $b` | 128×128 | 31.41 ms (0.1 GFLOP/s) | 0.08 ms (50.5 GFLOP/s) | 0.71 ms (5.9 GFLOP/s) | ×378.5 | ×44.0 |
| Multiplication `$a * $b` | 256×256 | 245.67 ms (0.1 GFLOP/s) | 0.32 ms (105.5 GFLOP/s) | 2.11 ms (15.9 GFLOP/s) | ×772.2 | ×116.6 |
| Multiplication `$a * $b` | 512×512 | 2,007.76 ms (0.1 GFLOP/s) | 2.35 ms (114.0 GFLOP/s) | 17.41 ms (15.4 GFLOP/s) | ×852.9 | ×115.3 |
| Multiplication `$a * $b` | 1024×1024 | 15,608.82 ms (0.1 GFLOP/s) | 19.64 ms (109.4 GFLOP/s) | 124.43 ms (17.3 GFLOP/s) | ×794.8 | ×125.4 |

The `blas` column is the kernel and almost nothing else: the operands are handed over as the buffers they are stored in, and the buffer the kernel writes becomes the result. The `php` column is the original array loops, walking PHP arrays — so every speed-up here is measured against the interpreter at its best, not against an interpreter slowed down by FFI.

Element-wise operations, which used to be the honest caveat of this table and are not one any more — with the cells already in the buffer, `cblas_daxpy` is called on the memory the matrices occupy and there is nothing left to marshal:

| Operation | Size | `php` | `blas` | `clblast` | `blas` speed-up | `clblast` speed-up |
| --- | --- | --- | --- | --- | --- | --- |
| Addition `$a + $b` | 64×64 | 0.10 ms | 0.03 ms | 0.50 ms | ×3.2 | ×0.2 |
| Addition `$a + $b` | 512×512 | 4.98 ms | 1.27 ms | 5.53 ms | ×3.9 | ×0.9 |
| Scaling `$a * 2.5` | 64×64 | 0.08 ms | 0.04 ms | 0.45 ms | ×1.8 | ×0.2 |
| Scaling `$a * 2.5` | 512×512 | 3.39 ms | 1.15 ms | 1.96 ms | ×2.9 | ×1.7 |

<sub>PHP 8.5.11 on Linux x86_64, Intel® Xeon® @ 2.10 GHz (shared cloud container), OpenBLAS 0.3.26, CLBlast 1.6.2 on PoCL 5.0 with `NATIVE_PHP_MATRIX_CL_DEVICE=cpu`. Median of 5 runs, one warm-up discarded, timing the whole PHP-level operation from operator to finished `Matrix`.</sub>

Storage follows the driver, and these tables show both halves of why. Buffers are what make the accelerated columns a kernel call with nothing to marshal. Arrays are what keep the `php` column fast: when every matrix was a buffer and the interpreter had to walk `FFI\CData`, the same container measured 4,146 ms for a 512×512 multiplication, 9.46 ms for the addition and 6.76 ms for the scaling — against 1,849 ms, 5.12 ms and 3.43 ms with the array loops back, about **twice as fast** across the board. Neither side pays for the other: the only conversion is the one a driver switch forces on the matrices built before it.

**These numbers are from a shared container without a GPU** — the `clblast` column is CLBlast running on the CPU through PoCL, which is a portability proof, not a GPU benchmark. On real hardware the GPU column is a different story, and the OpenBLAS column depends heavily on core count. Measure your own:

```bash
composer bench
composer bench -- --sizes=64,128,256,512,1024 --ops=gemm --repeat=5 --markdown
```

## How it works

Three moving parts, in order:

1. **`bootstrap.php`** is registered in Composer's `files` autoload, so it runs the moment you `require vendor/autoload.php`. It calls `ZEngine\Core::init()`, which validates that the FFI struct definitions match the exact PHP build you are running.
2. It then calls `installExtensionHandlers()` on `ZEngine\Reflection\ReflectionClass` for `Matrix`, wiring the class's `create_object`, `do_operation` and `compare` slots to the engine trampolines. Order matters — `create_object` allocates the memory the other handlers live in.
3. **`Matrix::__doOperation()`** and **`Matrix::__compare()`** are static hooks. The engine hands them a `DoOperationHook` / `CompareValuesHook` describing the opcode and both operands; they dispatch to ordinary, boring methods (`sum()`, `subtract()`, `multiply()`, `multiplyByScalar()`, `divideByScalar()`, `powByScalar()`, `equals()`), which ask the backend registry which driver should do the arithmetic and pass it the operands in the storage form it declares.

The maths is plain PHP — or plain BLAS, if you asked for it. The magic is only in getting the engine to call it.

## Requirements

- **PHP `^8.4`** — 8.4, 8.5 and 8.6 are supported in parallel. Z-Engine reads engine structures by byte offset and those offsets change on every PHP minor release, so each minor rides its own Z-Engine line; `Core::init()` refuses to boot on a mismatch rather than corrupting memory.
- **`ext-ffi` enabled**, with `ffi.enable=1` for CLI usage.
- **x64, non-thread-safe (NTS)** build — the same platform limitations as [Z-Engine](https://github.com/lisachenko/z-engine#requirements--support-matrix).
- The **matching Z-Engine minor release line**. Composer resolves it for you from the `~8.4.2 || ~8.5.0 || 8.6.x-dev` constraint; mixing a Z-Engine built for another minor is not a configuration choice, it is undefined behaviour.
- **On PHP 8.6 only:** Z-Engine has no stable 8.6 tag yet, so 8.6 rides its `8.6.x-dev` development line, and your project must allow that line at its root (see [Installation](#installation)).

## Installation

```bash
composer require lisachenko/native-php-matrix:dev-master
```

This package requires Z-Engine as `~8.4.2 || ~8.5.0 || 8.6.x-dev` — z-engine minors track PHP minors and are not interchangeable, so Composer resolves the line matching your PHP automatically (`8.4.x` on PHP 8.4, `8.5.x` on PHP 8.5, `8.6.x-dev` on PHP 8.6). The 8.4 and 8.5 lines are **stable tags**, so no `minimum-stability` tweak is needed for them.

**On PHP 8.6**, Z-Engine is still a development branch until it tags 8.6, and Composer only honours stability flags written in *your* root `composer.json` — not the ones a dependency declares. Require the line explicitly alongside this package:

```bash
composer require lisachenko/native-php-matrix:dev-master lisachenko/z-engine:8.6.x-dev
```

(or set `"minimum-stability": "dev"` with `"prefer-stable": true` in your project). Once Z-Engine tags 8.6, this package tightens its constraint to that tag and the extra requirement can be dropped.

native-php-matrix itself is not tagged yet, which is why the command above asks for `dev-master`; that one dev constraint is explicit, so Composer accepts it without a root stability change. Once a native-php-matrix release is tagged, this collapses to a plain `composer require lisachenko/native-php-matrix`.

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

### Casting

Casts are dispatched by the engine too — the `cast_object` and `get_properties_for` handlers:

```php
$m = new Matrix([[1, 2], [3, 4]]);

var_dump((array) $m);  // [[1, 2], [3, 4]] — the rows, not the object's internals

echo (string) $m;      // [1, 2]
                       // [3, 4]

var_dump((bool) $m);   // bool(true) — a valid matrix is never empty by construction
```

Numeric casts keep the engine's default behaviour (`(int) $m` gives `1`), and everything that is
not a cast — `var_dump()`, `serialize()`, `var_export()`, `json_encode()`, `get_object_vars()` —
still sees the object exactly as before.

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
- **Friendly `var_dump()`** — a `get_debug_info` handler that prints the matrix instead of its internals
- ~~**FFI BLAS backend**~~ — **done**: see [⚡ Acceleration](#-acceleration), with OpenBLAS on the CPU and CLBlast on the GPU behind an interchangeable driver interface
- **More BLAS coverage** — transposition, `gemv`, in-place accumulation and the fused `$input * $weights + $bias` of an inference layer
- **More drivers** — cuBLAS, a `ggml` driver for the Vulkan route, Metal — same `BackendInterface`, PRs welcome

## Contributing

The repository ships an agent/contributor contract in **[CLAUDE.md](CLAUDE.md)** — read it before changing anything, especially the section on why the PHP version is pinned.

```bash
composer test        # PHPUnit 12, .phpt functional suite
composer phpstan     # static analysis at level max
composer cs:check    # coding standards (PER-CS2.0); composer cs:fix to apply
composer bench       # compare the drivers on your own hardware
```

## License

Released under the [MIT License](LICENSE).
