--TEST--
Debugging a Matrix keeps the default engine property table with visibility markers
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

$matrix = new Matrix([[1, 2]]);
ob_start();
var_dump($matrix);
$output = ob_get_clean();
var_dump(str_contains($output, '["matrix":"Lisachenko\NativePhpMatrix\Matrix":private]'));
var_dump(str_contains($output, '["rows":"Lisachenko\NativePhpMatrix\Matrix":private]'));
var_dump(str_contains($output, '["columns":"Lisachenko\NativePhpMatrix\Matrix":private]'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
