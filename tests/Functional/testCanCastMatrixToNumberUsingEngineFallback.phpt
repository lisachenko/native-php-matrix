--TEST--
Numeric casts of a Matrix fall back to the default engine behaviour (warning and substitute value)
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
--EXPECTREGEX--
Warning: Object of class Lisachenko\\NativePhpMatrix\\Matrix could not be converted to int in .+ on line \d+
int\(1\)

Warning: Object of class Lisachenko\\NativePhpMatrix\\Matrix could not be converted to float in .+ on line \d+
float\(1\)
