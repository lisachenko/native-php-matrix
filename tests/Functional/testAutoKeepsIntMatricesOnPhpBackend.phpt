--TEST--
Automatic routing keeps all-integer arithmetic on the pure PHP backend
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--ENV--
NATIVE_PHP_MATRIX_BACKEND=
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Backend\Backends;
use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

// Accelerated drivers compute in double precision. Automatic routing therefore never hands them an all-integer
// operation, whether or not an acceleration library is installed on this machine: integers stay integers
var_dump(Backends::active());

$matrixA = new Matrix([[2, 3]]);
$matrixB = new Matrix([[4, 5]]);
var_dump(($matrixA + $matrixB)->toArray());
var_dump(($matrixA * 3)->toArray());
?>
--EXPECT--
string(4) "auto"
array(1) {
  [0]=>
  array(2) {
    [0]=>
    int(6)
    [1]=>
    int(8)
  }
}
array(1) {
  [0]=>
  array(2) {
    [0]=>
    int(6)
    [1]=>
    int(9)
  }
}
