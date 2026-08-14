--TEST--
The blas backend raises a matrix to a power in floats
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--ENV--
NATIVE_PHP_MATRIX_BACKEND=blas
--SKIPIF--
<?php
include __DIR__ . '/include/skipif_blas.inc';
?>
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

// BLAS has no exponentiation primitive: the driver loops in PHP, but keeps its float-only promise
$matrix = new Matrix([[2, 3], [4, 5]]);
var_dump(($matrix ** 2)->toArray());
?>
--EXPECT--
array(2) {
  [0]=>
  array(2) {
    [0]=>
    float(4)
    [1]=>
    float(9)
  }
  [1]=>
  array(2) {
    [0]=>
    float(16)
    [1]=>
    float(25)
  }
}
