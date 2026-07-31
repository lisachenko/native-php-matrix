--TEST--
Matrix can not be created from rows with a different number of columns
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
    $matrix = new Matrix([[1, 2], [3]]);
} catch (InvalidArgumentException $exception) {
    echo get_class($exception), ': ', $exception->getMessage(), PHP_EOL;
}
?>
--EXPECT--
InvalidArgumentException: All matrix rows should have the same number of columns
