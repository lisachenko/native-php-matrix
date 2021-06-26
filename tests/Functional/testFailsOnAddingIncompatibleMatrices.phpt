--TEST--
Matrices with incompatible dimensions can not be added
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

$matrixA = new Matrix([[1, 2, 3]]);
$matrixB = new Matrix([[4, 5, 6, 7]]);
$result  = $matrixA + $matrixB;
var_dump($result);
?>
--EXPECTREGEX--
Inconsistent matrix supplied
