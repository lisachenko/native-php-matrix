<?php

/**
 * Native matrix library
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */
declare(strict_types=1);

use FFI\CData;
use Lisachenko\NativePhpMatrix\Backend\BackendInterface;
use Lisachenko\NativePhpMatrix\Backend\Float64Buffer;
use Lisachenko\NativePhpMatrix\Backend\PhpBackend;

/**
 * Third-party driver stub that marks the cells it computes
 *
 * A real out-of-tree driver would call into its own library here. This one adds an unmistakable offset to every
 * sum instead, so that seeing the marker in the result of `$a + $b` proves the operator hook went all the way
 * through the registry into a driver that this package does not ship. Every other operation is delegated.
 *
 * It also stands for the buffer contract: the operands are read, never written, and the result is a buffer this
 * driver allocated itself.
 */
final class MarkerBackend implements BackendInterface
{
    public const int MARKER = 1000;

    private PhpBackend $delegate;

    public function __construct()
    {
        $this->delegate = new PhpBackend();
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function sum(CData $left, CData $right, int $rows, int $columns): CData
    {
        $count  = $rows * $columns;
        $result = Float64Buffer::allocate($count);
        for ($cell = 0; $cell < $count; $cell++) {
            $result[$cell] = $left[$cell] + $right[$cell] + self::MARKER;
        }

        return $result;
    }

    public function subtract(CData $left, CData $right, int $rows, int $columns): CData
    {
        return $this->delegate->subtract($left, $right, $rows, $columns);
    }

    public function multiply(CData $left, CData $right, int $rows, int $inner, int $columns): CData
    {
        return $this->delegate->multiply($left, $right, $rows, $inner, $columns);
    }

    public function multiplyByScalar(CData $matrix, float $value, int $rows, int $columns): CData
    {
        return $this->delegate->multiplyByScalar($matrix, $value, $rows, $columns);
    }

    public function divideByScalar(CData $matrix, float $value, int $rows, int $columns): CData
    {
        return $this->delegate->divideByScalar($matrix, $value, $rows, $columns);
    }

    public function powByScalar(CData $matrix, float $value, int $rows, int $columns): CData
    {
        return $this->delegate->powByScalar($matrix, $value, $rows, $columns);
    }
}
