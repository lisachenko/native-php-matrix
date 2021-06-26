--TEST--
Compatible matrices can be multiplied with "*" operator
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

$matrixA = new Matrix([[1, 2, 3]]);
$matrixB = new Matrix([[4], [5], [6]]);
$result  = $matrixA * $matrixB;
var_dump($result->toArray());
?>
--EXPECT--
array(1) {
  [0]=>
  array(1) {
    [0]=>
    int(32)
  }
}
