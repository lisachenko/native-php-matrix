--TEST--
The clblast backend scales a matrix by a scalar on an OpenCL device
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

$matrix = new Matrix([[1.5, 2.5], [3.5, 4.5]]);
var_dump(($matrix * 2)->toArray());
?>
--EXPECT--
array(2) {
  [0]=>
  array(2) {
    [0]=>
    float(3)
    [1]=>
    float(5)
  }
  [1]=>
  array(2) {
    [0]=>
    float(7)
    [1]=>
    float(9)
  }
}
