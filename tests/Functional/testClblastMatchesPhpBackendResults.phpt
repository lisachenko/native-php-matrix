--TEST--
The clblast backend produces the same product as the pure PHP backend
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--ENV--
NATIVE_PHP_MATRIX_BACKEND=clblast
--SKIPIF--
<?php
include __DIR__ . '/include/skipif_clblast.inc';
?>
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Backend\Backends;
use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

// Integral float cells and a small shared dimension keep every partial sum exactly representable in double
// precision, so the device and the interpreter must agree bit for bit despite accumulating in a different order
$size  = 8;
$left  = [];
$right = [];
for ($row = 0; $row < $size; $row++) {
    $leftRow  = [];
    $rightRow = [];
    for ($column = 0; $column < $size; $column++) {
        $leftRow[]  = (float) (($row * $size + $column) % 7 - 3);
        $rightRow[] = (float) (($row + 2 * $column) % 5 - 2);
    }
    $left[]  = $leftRow;
    $right[] = $rightRow;
}

$matrixA = new Matrix($left);
$matrixB = new Matrix($right);

Backends::use('php');
$expected = $matrixA * $matrixB;

Backends::use('clblast');
$actual = $matrixA * $matrixB;

var_dump($expected->toArray() === $actual->toArray());
var_dump($expected == $actual);
?>
--EXPECT--
bool(true)
bool(true)
