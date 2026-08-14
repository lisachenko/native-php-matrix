--TEST--
Matrix can be raised to the power with "**" operator
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

$matrixA = new Matrix([[1, 2, 3]]);
$result  = $matrixA ** 2;
var_dump($result->toArray());
?>
--EXPECT--
array(1) {
  [0]=>
  array(3) {
    [0]=>
    float(1)
    [1]=>
    float(4)
    [2]=>
    float(9)
  }
}
