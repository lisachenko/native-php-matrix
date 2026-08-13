--TEST--
The clblast backend multiplies float matrices on an OpenCL device
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

$matrixA = new Matrix([[1.0, 2.0], [3.0, 4.0]]);
$matrixB = new Matrix([[5.0, 6.0], [7.0, 8.0]]);
var_dump(($matrixA * $matrixB)->toArray());
?>
--EXPECT--
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
