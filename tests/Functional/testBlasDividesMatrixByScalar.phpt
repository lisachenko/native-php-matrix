--TEST--
The blas backend divides a matrix by a scalar by scaling with its reciprocal
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

// BLAS scales instead of dividing, so this driver multiplies by 1/4. Powers of two make that reciprocal exact,
// which is why the fixture uses one: any other divisor may differ from a true division in the last bit
$matrix = new Matrix([[2.0, 6.0], [10.0, 14.0]]);
var_dump(($matrix / 4)->toArray());
?>
--EXPECT--
array(2) {
  [0]=>
  array(2) {
    [0]=>
    float(0.5)
    [1]=>
    float(1.5)
  }
  [1]=>
  array(2) {
    [0]=>
    float(2.5)
    [1]=>
    float(3.5)
  }
}
