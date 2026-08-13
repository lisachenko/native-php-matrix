--TEST--
Selecting a backend that is not registered fails with a catchable exception
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--ENV--
NATIVE_PHP_MATRIX_BACKEND=
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Backend\Backends;

include __DIR__ . '/../../vendor/autoload.php';

// Selection happens in ordinary userland code, so unlike a failure inside an operator hook this one is catchable
try {
    Backends::use('quantum');
} catch (InvalidArgumentException $exception) {
    echo $exception->getMessage(), PHP_EOL;
}
var_dump(Backends::active());
?>
--EXPECTF--
Unknown matrix backend "quantum", registered ones are: %s
string(4) "auto"
