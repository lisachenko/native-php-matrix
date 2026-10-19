--TEST--
A matrix stores its cells in the form of the driver active when it is built, and results in the form of the driver that computed them
--INI--
ffi.enable=1
opcache.jit=off
--ENV--
NATIVE_PHP_MATRIX_BACKEND=php
--SKIPIF--
<?php
include __DIR__ . '/include/skipif_blas.inc';
?>
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Backend\Backends;
use Lisachenko\NativePhpMatrix\Backend\Driver;
use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

// Reads the private storage the way only Matrix itself could, to see the form rather than the values
$storageOf = Closure::bind(static fn (Matrix $matrix): string => get_debug_type($matrix->cells), null, Matrix::class);

Backends::use(Driver::Php);
$rows = new Matrix([[1, 2], [3, 4]]);
var_dump($storageOf($rows));
var_dump($storageOf($rows * $rows));

Backends::use(Driver::Blas);
$buffer = new Matrix([[1, 2], [3, 4]]);
var_dump($storageOf($buffer));
var_dump($storageOf($buffer * $buffer));

// The form is invisible from the outside: the same cells, the same products, whichever driver stored them
var_dump($rows->toArray() === $buffer->toArray());
var_dump(($buffer * $buffer)->toArray());
?>
--EXPECT--
string(5) "array"
string(5) "array"
string(9) "FFI\CData"
string(9) "FFI\CData"
bool(true)
array(2) {
  [0]=>
  array(2) {
    [0]=>
    float(7)
    [1]=>
    float(10)
  }
  [1]=>
  array(2) {
    [0]=>
    float(15)
    [1]=>
    float(22)
  }
}
