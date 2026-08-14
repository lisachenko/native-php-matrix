--TEST--
Matrix arithmetic defaults to automatic backend routing with pure PHP always available
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

var_dump(Backends::active());
var_dump(in_array('php', Backends::available(), true));
?>
--EXPECT--
enum(Lisachenko\NativePhpMatrix\Backend\Driver::Auto)
bool(true)
