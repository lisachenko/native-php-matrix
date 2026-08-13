--TEST--
Selecting a registered backend that is unavailable fails with a catchable exception
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--ENV--
NATIVE_PHP_MATRIX_BACKEND=
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Backend\BackendInterface;
use Lisachenko\NativePhpMatrix\Backend\BackendNotAvailableException;
use Lisachenko\NativePhpMatrix\Backend\Backends;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/include/UnavailableBackend.inc';

Backends::register('unavailable', static fn (): BackendInterface => new UnavailableBackend());

var_dump(in_array('unavailable', Backends::registered(), true));
var_dump(in_array('unavailable', Backends::available(), true));

try {
    Backends::use('unavailable');
} catch (BackendNotAvailableException $exception) {
    echo $exception->getMessage(), PHP_EOL;
}
var_dump(Backends::active());
?>
--EXPECT--
bool(true)
bool(false)
Matrix backend "unavailable" is registered but not available in this environment
string(4) "auto"
