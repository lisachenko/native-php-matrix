--TEST--
Matrix can be cast to bool with "(bool)" operator and is always truthy
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

$matrix = new Matrix([[0]]);
var_dump((bool) $matrix);
if ($matrix) {
    echo "truthy\n";
}
?>
--EXPECT--
bool(true)
truthy
