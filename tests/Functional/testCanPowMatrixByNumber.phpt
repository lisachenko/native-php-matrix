--TEST--
Matrix can be raised to the power with "**" operator
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
    int(1)
    [1]=>
    int(4)
    [2]=>
    int(9)
  }
}
