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
use Lisachenko\NativePhpMatrix\Backend\PhpBackend;

/**
 * Third-party driver stub that marks the cells it computes
 *
 * A real out-of-tree driver would call into its own library here. This one adds an unmistakable offset to every
 * sum instead, so that seeing the marker in the result of `$a + $b` proves the operator hook went all the way
 * through the registry into a driver that this package does not ship. Every other operation is delegated.
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

    public function sum(array $left, array $right, int $rows, int $columns): array
    {
        $result = [];
        foreach ($left as $rowIndex => $row) {
            $anotherRow = $right[$rowIndex];
            $resultRow  = [];
            foreach ($row as $columnIndex => $cellValue) {
                $resultRow[] = $cellValue + $anotherRow[$columnIndex] + self::MARKER;
            }
            $result[] = $resultRow;
        }

        return $result;
    }

    public function subtract(array $left, array $right, int $rows, int $columns): array
    {
        return $this->delegate->subtract($left, $right, $rows, $columns);
    }

    public function multiply(array $left, array $right, int $rows, int $inner, int $columns): array
    {
        return $this->delegate->multiply($left, $right, $rows, $inner, $columns);
    }

    public function multiplyByScalar(array $matrix, int|float $value, int $rows, int $columns): array
    {
        return $this->delegate->multiplyByScalar($matrix, $value, $rows, $columns);
    }

    public function divideByScalar(array $matrix, int|float $value, int $rows, int $columns): array
    {
        return $this->delegate->divideByScalar($matrix, $value, $rows, $columns);
    }

    public function powByScalar(array $matrix, int|float $value, int $rows, int $columns): array
    {
        return $this->delegate->powByScalar($matrix, $value, $rows, $columns);
    }
}
