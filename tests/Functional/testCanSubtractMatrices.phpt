--TEST--
Matrices can be subsctracted with "-" operator
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

$matrixA = new Matrix([[4, 5, 6]]);
$matrixB = new Matrix([[1, 2, 3]]);
$result  = $matrixA - $matrixB;
var_dump($result->toArray());
?>
--EXPECT--
array(1) {
  [0]=>
  array(3) {
    [0]=>
    int(3)
    [1]=>
    int(3)
    [2]=>
    int(3)
  }
}
