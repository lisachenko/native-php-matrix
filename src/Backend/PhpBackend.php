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

use function array_column;
use function array_keys;

/**
 * Reference driver: the original interpreted PHP arithmetic
 *
 * The loop bodies are the ones this library shipped before the drivers existed, kept verbatim down to the
 * iteration order. That matters for more than nostalgia: PHP arithmetic preserves integers, and summing the
 * products of a row in a different order can change the last bits of a float result. This driver is always
 * available, it is the fallback of every other one, and it is the only driver that returns integers.
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
                $cellValue = 0;
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
    public function multiplyByScalar(array $matrix, int|float $value, int $rows, int $columns): array
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
    public function divideByScalar(array $matrix, int|float $value, int $rows, int $columns): array
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
    public function powByScalar(array $matrix, int|float $value, int $rows, int $columns): array
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
