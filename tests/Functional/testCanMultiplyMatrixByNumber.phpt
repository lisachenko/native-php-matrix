--TEST--
Matrix can be multiplied by a number with "*" operator
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

$matrixA = new Matrix([[1, 2, 3]]);
$result  = $matrixA * 3;
var_dump($result->toArray());
?>
--EXPECT--
array(1) {
  [0]=>
  array(3) {
    [0]=>
    int(3)
    [1]=>
    int(6)
    [2]=>
    int(9)
  }
}
