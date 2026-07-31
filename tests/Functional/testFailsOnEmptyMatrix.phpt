--TEST--
Matrix can not be created from an empty array
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

try {
    $matrix = new Matrix([]);
} catch (InvalidArgumentException $exception) {
    echo get_class($exception), ': ', $exception->getMessage(), PHP_EOL;
}
?>
--EXPECT--
InvalidArgumentException: Matrix should contain at least one row
