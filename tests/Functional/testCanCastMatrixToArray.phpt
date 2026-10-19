--TEST--
Matrix can be cast to array with "(array)" operator
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

$matrix = new Matrix([[1, 2, 3], [4, 5, 6]]);
var_dump((array) $matrix);
?>
--EXPECT--
array(2) {
  [0]=>
  array(3) {
    [0]=>
    float(1)
    [1]=>
    float(2)
    [2]=>
    float(3)
  }
  [1]=>
  array(3) {
    [0]=>
    float(4)
    [1]=>
    float(5)
    [2]=>
    float(6)
  }
}
