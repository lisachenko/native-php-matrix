--TEST--
The clblast backend adds matrices and returns floats even for integer input
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--ENV--
NATIVE_PHP_MATRIX_BACKEND=clblast
--SKIPIF--
<?php
include __DIR__ . '/include/skipif_clblast.inc';
?>
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

// Device kernels are double precision, so an explicitly selected GPU driver returns floats for integer cells too
$matrixA = new Matrix([[1, 2, 3]]);
$matrixB = new Matrix([[4, 5, 6]]);
var_dump(($matrixA + $matrixB)->toArray());
?>
--EXPECT--
array(1) {
  [0]=>
  array(3) {
    [0]=>
    float(5)
    [1]=>
    float(7)
    [2]=>
    float(9)
  }
}
