--TEST--
Selecting a registered backend that implements no storage contract fails with a catchable exception
--INI--
ffi.enable=1
opcache.jit=off
--ENV--
NATIVE_PHP_MATRIX_BACKEND=
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Backend\BackendInterface;
use Lisachenko\NativePhpMatrix\Backend\Backends;

include __DIR__ . '/../../vendor/autoload.php';

// Available, but neither an array driver nor a buffer driver: no matrix could be stored for it
final class StoragelessBackend implements BackendInterface
{
    public function isAvailable(): bool
    {
        return true;
    }
}

Backends::register('storageless', static fn (): BackendInterface => new StoragelessBackend());

try {
    Backends::use('storageless');
} catch (InvalidArgumentException $exception) {
    echo $exception->getMessage(), PHP_EOL;
}
var_dump(Backends::active());
?>
--EXPECT--
Matrix backend "storageless" implements neither Lisachenko\NativePhpMatrix\Backend\ArrayBackendInterface nor Lisachenko\NativePhpMatrix\Backend\BufferBackendInterface, so no matrix can be stored for it
enum(Lisachenko\NativePhpMatrix\Backend\Driver::Auto)
