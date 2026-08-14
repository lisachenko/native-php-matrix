--TEST--
Matrix accepts integer literals and stores every cell as float64
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

// Integers are a convenience of the literal, not a cell type: they are converted while the constructor writes
// them into the native buffer, exactly the way numpy.array([[1, 2]]) yields dtype=float64
$matrix = new Matrix([[1, 2], [3, 4.5]]);
var_dump($matrix->toArray());

// Which means the result of an operation is float whichever driver computed it, with no widening left to do
var_dump(($matrix * 2)->toArray());

// Integral floats still stringify without a fractional part, so a matrix of whole numbers reads as before
echo (string) new Matrix([[1, 2, 3]]), "\n";
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
array(2) {
  [0]=>
  array(2) {
    [0]=>
    float(2)
    [1]=>
    float(4)
  }
  [1]=>
  array(2) {
    [0]=>
    float(6)
    [1]=>
    float(9)
  }
}
[1, 2, 3]
