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

namespace Lisachenko\NativePhpMatrix\Backend;

use FFI\CData;

/**
 * Reference driver: the arithmetic carried out by the interpreter itself
 *
 * The loop bodies are the ones this library shipped before the drivers existed; what changed is what they walk
 * over. Cells now live in a flat `double[]` buffer rather than in nested arrays, so the loops index offsets
 * instead of dereferencing rows, and the multiplication keeps its original accumulation order — summing the
 * products of a row in a different order can change the last bits of a float result, and the parity tests hold
 * this driver and the accelerated ones to the same answer.
 *
 * Reading and writing a cell through `FFI\CData` is slower than touching a PHP array element, which makes this
 * driver slower than it used to be on paper. That is a deliberate trade: it is the fallback that must work with
 * no library installed at all, while every operation that matters for speed is now handed to a kernel without a
 * conversion in between.
 */
final class PhpBackend implements BackendInterface
{
    /**
     * Pure PHP is available wherever this library runs
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function sum(CData $left, CData $right, int $rows, int $columns): CData
    {
        $count  = $rows * $columns;
        $result = Float64Buffer::allocate($count);
        for ($cell = 0; $cell < $count; $cell++) {
            $result[$cell] = $left[$cell] + $right[$cell];
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function subtract(CData $left, CData $right, int $rows, int $columns): CData
    {
        $count  = $rows * $columns;
        $result = Float64Buffer::allocate($count);
        for ($cell = 0; $cell < $count; $cell++) {
            $result[$cell] = $left[$cell] - $right[$cell];
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function multiply(CData $left, CData $right, int $rows, int $inner, int $columns): CData
    {
        // The multiplier is consumed column by column, and a column of a row-major buffer is strided: consecutive
        // cells sit $columns doubles apart, so at any real size every read is a cache miss. Transposing it once,
        // for O(inner * columns), is the buffer-level form of the array_column() this loop used to do — after it
        // both operands are walked contiguously in the inner loop
        $transposed = Float64Buffer::allocate($inner * $columns);
        for ($row = 0; $row < $inner; $row++) {
            $rowOffset = $row * $columns;
            for ($column = 0; $column < $columns; $column++) {
                $transposed[$column * $inner + $row] = $right[$rowOffset + $column];
            }
        }

        $result = Float64Buffer::allocate($rows * $columns);
        for ($row = 0; $row < $rows; $row++) {
            $leftOffset   = $row * $inner;
            $resultOffset = $row * $columns;
            for ($column = 0; $column < $columns; $column++) {
                $columnOffset = $column * $inner;
                // Summation order is unchanged, which is what keeps this driver bit-identical to the kernels
                $cellValue = 0.0;
                for ($step = 0; $step < $inner; $step++) {
                    $cellValue += $left[$leftOffset + $step] * $transposed[$columnOffset + $step];
                }
                $result[$resultOffset + $column] = $cellValue;
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function multiplyByScalar(CData $matrix, float $value, int $rows, int $columns): CData
    {
        $count  = $rows * $columns;
        $result = Float64Buffer::allocate($count);
        for ($cell = 0; $cell < $count; $cell++) {
            $result[$cell] = $matrix[$cell] * $value;
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function divideByScalar(CData $matrix, float $value, int $rows, int $columns): CData
    {
        $count  = $rows * $columns;
        $result = Float64Buffer::allocate($count);
        for ($cell = 0; $cell < $count; $cell++) {
            $result[$cell] = $matrix[$cell] / $value;
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function powByScalar(CData $matrix, float $value, int $rows, int $columns): CData
    {
        $count  = $rows * $columns;
        $result = Float64Buffer::allocate($count);
        for ($cell = 0; $cell < $count; $cell++) {
            $result[$cell] = $matrix[$cell] ** $value;
        }

        return $result;
    }
}
