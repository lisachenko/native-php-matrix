--TEST--
Debugging a Matrix keeps the default engine property table with visibility markers
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

// The cells live in a native buffer now, so the property table shows a CData handle where it used to show an
// array of rows. What the test is about is unchanged: the engine's own visibility markers survive the hook
$matrix = new Matrix([[1, 2]]);
ob_start();
var_dump($matrix);
$output = ob_get_clean();
var_dump(str_contains($output, '["buffer":"Lisachenko\NativePhpMatrix\Matrix":private]'));
var_dump(str_contains($output, 'object(FFI\CData:double[2])'));
var_dump(str_contains($output, '["rows":"Lisachenko\NativePhpMatrix\Matrix":private]'));
var_dump(str_contains($output, '["columns":"Lisachenko\NativePhpMatrix\Matrix":private]'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
