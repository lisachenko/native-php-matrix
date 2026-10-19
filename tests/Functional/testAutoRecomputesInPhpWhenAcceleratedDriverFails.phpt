--TEST--
Automatic routing recomputes an operation on the pure PHP driver when the accelerated driver fails at operation time
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
use Lisachenko\NativePhpMatrix\Backend\BufferBackendInterface;
use Lisachenko\NativePhpMatrix\Backend\Driver;
use Lisachenko\NativePhpMatrix\Matrix;

include __DIR__ . '/../../vendor/autoload.php';

// Probes fine, then fails every operation, the way a device that is reset after the probe would
final class FailingBufferBackend implements BufferBackendInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function sum(FFI\CData $left, FFI\CData $right, int $rows, int $columns): FFI\CData
    {
        throw new RuntimeException('device lost');
    }

    public function subtract(FFI\CData $left, FFI\CData $right, int $rows, int $columns): FFI\CData
    {
        throw new RuntimeException('device lost');
    }

    public function multiply(FFI\CData $left, FFI\CData $right, int $rows, int $inner, int $columns): FFI\CData
    {
        throw new RuntimeException('device lost');
    }

    public function multiplyByScalar(FFI\CData $matrix, float $value, int $rows, int $columns): FFI\CData
    {
        throw new RuntimeException('device lost');
    }

    public function divideByScalar(FFI\CData $matrix, float $value, int $rows, int $columns): FFI\CData
    {
        throw new RuntimeException('device lost');
    }

    public function powByScalar(FFI\CData $matrix, float $value, int $rows, int $columns): FFI\CData
    {
        throw new RuntimeException('device lost');
    }
}

// Automatic routing picks whatever is registered as "blas" when it probes available, and wraps it in the fallback
Backends::register(Driver::Blas, static fn (): BackendInterface => new FailingBufferBackend());

// Matrices built now are buffers, so the fallback has to read them as rows and hand a buffer back. Non-square
// shapes make a transposed dimension in that conversion visible
$left  = new Matrix([[1, 2, 3], [4, 5, 6]]);
$right = new Matrix([[1, 0], [0, 1], [1, 1]]);

// An exception crossing the operator hook would be a fatal error: reaching the dumps proves it was caught
var_dump(($left * $right)->toArray());
var_dump(($left + $left)->toArray() === ($left * 2)->toArray());
var_dump(($left - $left) == new Matrix([[0, 0, 0], [0, 0, 0]]));
?>
--EXPECT--
array(2) {
  [0]=>
  array(2) {
    [0]=>
    float(4)
    [1]=>
    float(5)
  }
  [1]=>
  array(2) {
    [0]=>
    float(10)
    [1]=>
    float(11)
  }
}
bool(true)
bool(true)
