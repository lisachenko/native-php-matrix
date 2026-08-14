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

use FFI;
use FFI\CData;

/**
 * Shared plumbing of the drivers that hand their arithmetic to a numeric library
 *
 * Every such library speaks contiguous double precision memory, while this package speaks lists of rows. The two
 * conversions live here, together with the one operation no BLAS implementation provides.
 *
 * The conversion is also where the float-only promise of these drivers is kept: packing casts each cell to a
 * double, and unpacking returns floats, so an integer matrix that reaches an accelerated driver comes back as a
 * float one. Automatic routing avoids that by keeping integer operations on the pure-PHP driver; an explicit
 * selection accepts it deliberately.
 */
trait AcceleratedBackendTrait
{
    /**
     * {@inheritDoc}
     */
    public function powByScalar(array $matrix, int|float $value, int $rows, int $columns): array
    {
        // Exponentiation is not part of BLAS: it stays an ordinary loop, in floats, so that an accelerated driver
        // answers every overloaded operator with the same cell type
        $result = [];
        foreach ($matrix as $row) {
            $resultRow = [];
            foreach ($row as $cellValue) {
                $resultRow[] = ((float) $cellValue) ** $value;
            }
            $result[] = $resultRow;
        }

        return $result;
    }

    /**
     * Copies a matrix into a freshly allocated row-major buffer of doubles
     *
     * The buffer is owned by PHP: it is released when the returned handle goes out of scope, which is why no
     * driver in this package frees host memory by hand.
     *
     * @param non-empty-list<non-empty-list<int|float>> $matrix  Cells to copy
     * @param positive-int                              $rows    Number of rows
     * @param positive-int                              $columns Number of columns
     *
     * @return CData Owned `double[rows * columns]` buffer holding the cells row by row
     */
    private function packRowMajor(array $matrix, int $rows, int $columns): CData
    {
        $buffer = $this->allocate($rows * $columns);
        $this->fillRowMajor($buffer, $matrix);

        return $buffer;
    }

    /**
     * Writes the cells of a matrix into a buffer, row by row
     *
     * @param CData                                     $buffer Buffer with room for every cell
     * @param non-empty-list<non-empty-list<int|float>> $matrix Cells to write
     */
    private function fillRowMajor(CData $buffer, array $matrix): void
    {
        $offset = 0;
        foreach ($matrix as $row) {
            foreach ($row as $cellValue) {
                $buffer[$offset++] = (float) $cellValue;
            }
        }
    }

    /**
     * Allocates an owned, zero-filled buffer of doubles
     *
     * @param positive-int $count Number of doubles to reserve
     *
     * @return CData Owned `double[count]` buffer
     */
    private function allocate(int $count): CData
    {
        return $this->library()->new('double[' . $count . ']');
    }

    /**
     * Reads a row-major buffer of doubles back into a list of rows
     *
     * @param CData        $buffer  Buffer holding at least rows * columns doubles
     * @param positive-int $rows    Number of rows to read
     * @param positive-int $columns Number of columns to read
     *
     * @return non-empty-list<non-empty-list<float>> Cells of the computed matrix
     */
    private function unpackRows(CData $buffer, int $rows, int $columns): array
    {
        return array_map(
            fn(int $row): array => $this->unpackRow($buffer, $row * $columns, $columns),
            range(0, $rows - 1),
        );
    }

    /**
     * Reads a single row of doubles out of a row-major buffer
     *
     * @param CData        $buffer  Buffer holding the cells
     * @param int          $offset  Index of the first cell of the row
     * @param positive-int $columns Number of cells to read
     *
     * @return non-empty-list<float> Cells of one row
     */
    private function unpackRow(CData $buffer, int $offset, int $columns): array
    {
        return array_map(
            static fn(int $column): float => $buffer[$offset + $column],
            range(0, $columns - 1),
        );
    }

    /**
     * Returns the loaded library this driver computes with
     *
     * Declared here because the shared plumbing allocates its buffers through the very FFI binding the driver
     * loaded; every driver using this trait provides it.
     */
    abstract private function library(): FFI;
}
