--TEST--
Numeric casts of a Matrix fall back to the default engine value (the engine warning is intentionally absent)
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

$matrix = new Matrix([[1, 2], [3, 4]]);
var_dump((int) $matrix);
var_dump((float) $matrix);
?>
--EXPECT--
int(1)
float(1)
