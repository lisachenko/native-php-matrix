--TEST--
get_object_vars() on a Matrix returns the public view even for class-scoped callers (known deviation)
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

// Default engine behaviour would grant a Matrix-scoped closure the private properties, but the
// calling scope is not recoverable inside the get_properties_for FFI callback, so the handler
// deliberately serves every caller the public (empty) view — see Matrix::__getFields()
$matrix = new Matrix([[1, 2]]);
$scoped = Closure::bind(static fn (Matrix $m): array => get_object_vars($m), null, Matrix::class);
var_dump($scoped($matrix));
?>
--EXPECT--
array(0) {
}
