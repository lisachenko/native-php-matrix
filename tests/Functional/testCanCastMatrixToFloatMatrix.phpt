--TEST--
Matrix can be converted to a float matrix with asFloat()
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

$matrix = new Matrix([[1, 2], [3, 4.5]]);
var_dump($matrix->asFloat()->toArray());
?>
--EXPECT--
array(2) {
  [0]=>
  array(2) {
    [0]=>
    float(1)
    [1]=>
    float(2)
  }
  [1]=>
  array(2) {
    [0]=>
    float(3)
    [1]=>
    float(4.5)
  }
}
