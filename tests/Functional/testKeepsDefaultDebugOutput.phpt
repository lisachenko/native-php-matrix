--TEST--
Debugging a Matrix keeps the default engine property table with visibility markers
--INI--
ffi.enable=1
opcache.jit=off
--ENV--
NATIVE_PHP_MATRIX_BACKEND=php
--FILE--
<?php
declare(strict_types=1);

use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

// What the property table holds follows the driver — rows of floats under the pure-PHP driver, a CData handle under
// an accelerated one — so this test pins the driver instead of depending on which libraries the machine has. What
// it is about is unchanged: the engine's own visibility markers survive the hook
$matrix = new Matrix([[1, 2]]);
ob_start();
var_dump($matrix);
$output = ob_get_clean();
var_dump(str_contains($output, "[\"cells\":\"Lisachenko\\NativePhpMatrix\\Matrix\":private]=>\n  array(1) {"));
var_dump(str_contains($output, '["rows":"Lisachenko\NativePhpMatrix\Matrix":private]'));
var_dump(str_contains($output, '["columns":"Lisachenko\NativePhpMatrix\Matrix":private]'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
