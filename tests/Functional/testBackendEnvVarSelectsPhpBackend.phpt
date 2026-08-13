--TEST--
The NATIVE_PHP_MATRIX_BACKEND environment variable pins the backend at bootstrap
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--ENV--
NATIVE_PHP_MATRIX_BACKEND=php
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Backend\Backends;
use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

var_dump(Backends::active());

$matrixA = new Matrix([[1.5, 2.5]]);
$matrixB = new Matrix([[0.5, 0.5]]);
var_dump(($matrixA + $matrixB)->toArray());
?>
--EXPECT--
string(3) "php"
array(1) {
  [0]=>
  array(2) {
    [0]=>
    float(2)
    [1]=>
    float(3)
  }
}
