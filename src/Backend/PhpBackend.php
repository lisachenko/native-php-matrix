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

/**
 * Reference driver: the original interpreted PHP arithmetic, over PHP arrays
 *
 * The loop bodies are the ones this library shipped before the drivers existed, kept verbatim down to the
 * iteration order — summing the products of a row in a different order can change the last bits of a float
 * result, and the parity tests hold this driver and the accelerated ones to the same answer. The single
 * adaptation is the accumulator of the multiplication, which starts at `0.0`: cells are float64, and an integer
 * seed would be the one place an int could slip into a result.
 *
 * The driver declares {@see ArrayBackendInterface}, so the matrices built while it is active store their cells as
 * nested PHP arrays and these loops walk them natively. A `foreach` over a packed array is the fastest thing the
 * interpreter does; reading the same cells through `FFI\CData` costs two to three times as much, which is why the
 * storage follows the driver instead of the driver following one storage.
 */
final class PhpBackend implements ArrayBackendInterface
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
    public function sum(array $left, array $right, int $rows, int $columns): array
    {
        $result = [];
        foreach ($left as $rowIndex => $row) {
            $anotherRow = $right[$rowIndex];
            $resultRow  = [];
            foreach ($row as $columnIndex => $cellValue) {
                $resultRow[] = $cellValue + $anotherRow[$columnIndex];
            }
            $result[] = $resultRow;
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function subtract(array $left, array $right, int $rows, int $columns): array
    {
        $result = [];
        foreach ($left as $rowIndex => $row) {
            $anotherRow = $right[$rowIndex];
            $resultRow  = [];
            foreach ($row as $columnIndex => $cellValue) {
                $resultRow[] = $cellValue - $anotherRow[$columnIndex];
            }
            $result[] = $resultRow;
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function multiply(array $left, array $right, int $rows, int $inner, int $columns): array
    {
        // Columns of the multiplier are extracted only once, they are reused for every row of the left operand
        $multiplierColumns = [];
        foreach (array_keys($right[0]) as $column) {
            $multiplierColumns[] = array_column($right, $column);
        }

        $result = [];
        foreach ($left as $rowItems) {
            $resultRow = [];
            foreach ($multiplierColumns as $columnItems) {
                $cellValue = 0.0;
                foreach ($rowItems as $key => $value) {
                    $cellValue += $value * $columnItems[$key];
                }

                $resultRow[] = $cellValue;
            }
            $result[] = $resultRow;
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function multiplyByScalar(array $matrix, float $value, int $rows, int $columns): array
    {
        $result = [];
        foreach ($matrix as $row) {
            $resultRow = [];
            foreach ($row as $cellValue) {
                $resultRow[] = $cellValue * $value;
            }
            $result[] = $resultRow;
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function divideByScalar(array $matrix, float $value, int $rows, int $columns): array
    {
        $result = [];
        foreach ($matrix as $row) {
            $resultRow = [];
            foreach ($row as $cellValue) {
                $resultRow[] = $cellValue / $value;
            }
            $result[] = $resultRow;
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function powByScalar(array $matrix, float $value, int $rows, int $columns): array
    {
        $result = [];
        foreach ($matrix as $row) {
            $resultRow = [];
            foreach ($row as $cellValue) {
                $resultRow[] = $cellValue ** $value;
            }
            $result[] = $resultRow;
        }

        return $result;
    }
}
