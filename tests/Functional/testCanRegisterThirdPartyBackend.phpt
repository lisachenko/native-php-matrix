--TEST--
A third-party backend can be registered and serves the overloaded operators
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Backend\BackendInterface;
use Lisachenko\NativePhpMatrix\Backend\Backends;
use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/include/MarkerBackend.inc';

Backends::register('marker', static fn (): BackendInterface => new MarkerBackend());
Backends::use('marker');
var_dump(Backends::active());

// The engine dispatches "+" to the do_operation hook, which resolves the driver through the registry: seeing the
// marker offset in the result proves the whole path, from the operator down to an out-of-tree driver
$matrixA = new Matrix([[1, 2]]);
$matrixB = new Matrix([[10, 20]]);
var_dump(($matrixA + $matrixB)->toArray());
?>
--EXPECT--
string(6) "marker"
array(1) {
  [0]=>
  array(2) {
    [0]=>
    float(1011)
    [1]=>
    float(1022)
  }
}
