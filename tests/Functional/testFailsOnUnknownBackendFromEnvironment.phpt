--TEST--
An unknown backend in the environment fails while booting, as a catchable exception
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--ENV--
NATIVE_PHP_MATRIX_BACKEND=quantum
--FILE--
<?php
declare(strict_types=1);

// The environment selection is applied by the package bootstrap, which is ordinary userland code running long
// before any operator hook. A bad value therefore fails here, where it can still be caught, instead of turning
// into "Throwing from FFI callbacks is not allowed" at the first "+"
try {
    include __DIR__ . '/../../vendor/autoload.php';
} catch (InvalidArgumentException $exception) {
    echo get_class($exception), PHP_EOL;
    echo $exception->getMessage(), PHP_EOL;
}
?>
--EXPECTF--
InvalidArgumentException
Unknown matrix backend "quantum", registered ones are: %s
