--TEST--
Matrices can be added with "+" operator
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

$matrixA = new Matrix([[1, 2, 3]]);
$matrixB = new Matrix([[4, 5, 6]]);
$result  = $matrixA + $matrixB;
var_dump($result->toArray());
?>
--EXPECT--
array(1) {
  [0]=>
  array(3) {
    [0]=>
    int(5)
    [1]=>
    int(7)
    [2]=>
    int(9)
  }
}
