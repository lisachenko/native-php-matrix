--TEST--
Matrix can be cast to string with "(string)" operator
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

$matrix = new Matrix([[1, 2, 3], [4, 5.5, 6]]);
echo (string) $matrix, "\n";
?>
--EXPECT--
[1, 2, 3]
[4, 5.5, 6]
