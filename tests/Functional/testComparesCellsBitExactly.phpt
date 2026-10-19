--TEST--
Matrices are compared by the bits of their float64 cells, whichever storage holds them
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

// An int literal and the float it converts to are the same double
var_dump(new Matrix([[1, 2]]) == new Matrix([[1.0, 2.0]]));

// Storage-level comparison is stricter than "==" on the numbers: the sign bit of a zero counts...
var_dump(new Matrix([[0.0]]) == new Matrix([[-0.0]]));

// ...and two NAN cells with the same bit pattern are the same cell. Both hold for buffers compared with memcmp
// and for rows compared as their packed doubles, so the outcome does not depend on the driver that built them
var_dump(new Matrix([[NAN]]) == new Matrix([[NAN]]));
?>
--EXPECT--
bool(true)
bool(false)
bool(true)
