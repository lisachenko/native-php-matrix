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

/**
 * Driver that reports itself unusable, the way a driver whose library is missing does
 *
 * Its operations are never reachable: an unavailable driver cannot be selected, and automatic routing skips it.
 * Failing this deterministically, without depending on which acceleration libraries the machine happens to have,
 * is exactly why this stub exists. The bodies still honour the contract — a fresh buffer, never an operand — so
 * that the stub cannot become a counter-example to it.
 */
final class UnavailableBackend implements BackendInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function sum(CData $left, CData $right, int $rows, int $columns): CData
    {
        return Float64Buffer::copyOf($left, $rows * $columns);
    }

    public function subtract(CData $left, CData $right, int $rows, int $columns): CData
    {
        return Float64Buffer::copyOf($left, $rows * $columns);
    }

    public function multiply(CData $left, CData $right, int $rows, int $inner, int $columns): CData
    {
        return Float64Buffer::allocate($rows * $columns);
    }

    public function multiplyByScalar(CData $matrix, float $value, int $rows, int $columns): CData
    {
        return Float64Buffer::copyOf($matrix, $rows * $columns);
    }

    public function divideByScalar(CData $matrix, float $value, int $rows, int $columns): CData
    {
        return Float64Buffer::copyOf($matrix, $rows * $columns);
    }

    public function powByScalar(CData $matrix, float $value, int $rows, int $columns): CData
    {
        return Float64Buffer::copyOf($matrix, $rows * $columns);
    }
}
