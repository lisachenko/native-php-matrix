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

use Lisachenko\NativePhpMatrix\Backend\BackendInterface;

/**
 * Driver that reports itself unusable, the way a driver whose library is missing does
 *
 * Its operations are never reachable: an unavailable driver cannot be selected, and automatic routing skips it.
 * Failing this deterministically, without depending on which acceleration libraries the machine happens to have,
 * is exactly why this stub exists.
 */
final class UnavailableBackend implements BackendInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function sum(array $left, array $right, int $rows, int $columns): array
    {
        return $left;
    }

    public function subtract(array $left, array $right, int $rows, int $columns): array
    {
        return $left;
    }

    public function multiply(array $left, array $right, int $rows, int $inner, int $columns): array
    {
        return $left;
    }

    public function multiplyByScalar(array $matrix, int|float $value, int $rows, int $columns): array
    {
        return $matrix;
    }

    public function divideByScalar(array $matrix, int|float $value, int $rows, int $columns): array
    {
        return $matrix;
    }

    public function powByScalar(array $matrix, int|float $value, int $rows, int $columns): array
    {
        return $matrix;
    }
}
