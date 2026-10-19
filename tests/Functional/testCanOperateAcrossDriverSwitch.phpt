--TEST--
Matrices built under one driver can be operated on and compared under another
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

// Built under the pure-PHP driver: stored as rows of floats
Backends::use(Driver::Php);
$rows = new Matrix([[1, 2], [3, 4]]);

// Built under OpenBLAS: stored as a native buffer
Backends::use(Driver::Blas);
$buffer = new Matrix([[5, 6], [7, 8]]);

// OpenBLAS computes, so the rows operand is converted into a buffer once for each operation
var_dump(($rows + $buffer)->toArray());
var_dump(($rows * $buffer)->toArray());

// The pure-PHP driver computes, so the buffer operand is converted into rows once for each operation
Backends::use(Driver::Php);
var_dump(($buffer - $rows)->toArray());
var_dump(($buffer / 2)->toArray());

// Equality does not depend on the storage either
var_dump($rows == new Matrix([[1, 2], [3, 4]]));
Backends::use(Driver::Blas);
var_dump($rows == new Matrix([[1, 2], [3, 4]]));
var_dump($rows == $buffer);
?>
--EXPECT--
array(2) {
  [0]=>
  array(2) {
    [0]=>
    float(6)
    [1]=>
    float(8)
  }
  [1]=>
  array(2) {
    [0]=>
    float(10)
    [1]=>
    float(12)
  }
}
array(2) {
  [0]=>
  array(2) {
    [0]=>
    float(19)
    [1]=>
    float(22)
  }
  [1]=>
  array(2) {
    [0]=>
    float(43)
    [1]=>
    float(50)
  }
}
array(2) {
  [0]=>
  array(2) {
    [0]=>
    float(4)
    [1]=>
    float(4)
  }
  [1]=>
  array(2) {
    [0]=>
    float(4)
    [1]=>
    float(4)
  }
}
array(2) {
  [0]=>
  array(2) {
    [0]=>
    float(2.5)
    [1]=>
    float(3)
  }
  [1]=>
  array(2) {
    [0]=>
    float(3.5)
    [1]=>
    float(4)
  }
}
bool(true)
bool(true)
bool(false)
