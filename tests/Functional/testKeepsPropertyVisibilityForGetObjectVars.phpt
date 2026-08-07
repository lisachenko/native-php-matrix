--TEST--
get_object_vars() on a Matrix keeps default property visibility for outside callers
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

$matrix = new Matrix([[1, 2]]);
var_dump(get_object_vars($matrix));
?>
--EXPECT--
array(0) {
}
