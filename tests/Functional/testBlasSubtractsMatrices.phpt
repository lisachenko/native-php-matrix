--TEST--
The blas backend subtracts matrices with a negatively scaled cblas_daxpy
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

$matrixA = new Matrix([[10.0, 8.0], [6.0, 4.0]]);
$matrixB = new Matrix([[1.0, 2.0], [3.0, 4.0]]);
var_dump(($matrixA - $matrixB)->toArray());
?>
--EXPECT--
array(2) {
  [0]=>
  array(2) {
    [0]=>
    float(9)
    [1]=>
    float(6)
  }
  [1]=>
  array(2) {
    [0]=>
    float(3)
    [1]=>
    float(0)
  }
}
