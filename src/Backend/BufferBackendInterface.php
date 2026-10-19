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
 * Storage contract of a driver that computes on native float64 buffers
 *
 * The operands are the buffers the matrices are actually stored in, handed over without a copy. That is the whole
 * point of the shape: a PHP array would have to be packed into contiguous doubles before a native kernel could
 * touch it and unpacked afterwards, which for an element-wise operation costs more than the arithmetic itself.
 * Two obligations come with the privilege:
 *
 * - **Operands are read-only.** They belong to the matrices the caller still holds. A kernel that accumulates
 *   into one of its arguments — `daxpy` and `dscal` both do — copies it into the result buffer first
 *   ({@see Float64Buffer::copyOf()}) and works on the copy.
 * - **The result is a fresh allocation.** Never return an operand, and never return a buffer that outlives the
 *   call in some driver-owned cache: the returned buffer becomes the storage of a new matrix.
 */
interface BufferBackendInterface extends BackendInterface
{
    /**
     * Adds two matrices of the same shape element-wise
     *
     * @param CData        $left    Left operand cells, row-major `double[rows * columns]`
     * @param CData        $right   Right operand cells, same shape as the left one
     * @param positive-int $rows    Number of rows in both operands
     * @param positive-int $columns Number of columns in both operands
     *
     * @return CData Freshly allocated `double[rows * columns]` holding the sum
     */
    public function sum(CData $left, CData $right, int $rows, int $columns): CData;

    /**
     * Subtracts the right matrix from the left one element-wise
     *
     * @param CData        $left    Left operand cells, row-major `double[rows * columns]`
     * @param CData        $right   Right operand cells, same shape as the left one
     * @param positive-int $rows    Number of rows in both operands
     * @param positive-int $columns Number of columns in both operands
     *
     * @return CData Freshly allocated `double[rows * columns]` holding the difference
     */
    public function subtract(CData $left, CData $right, int $rows, int $columns): CData;

    /**
     * Multiplies two matrices with matching inner dimensions
     *
     * @param CData        $left    Left operand cells, row-major `double[rows * inner]`
     * @param CData        $right   Right operand cells, row-major `double[inner * columns]`
     * @param positive-int $rows    Number of rows of the left operand
     * @param positive-int $inner   Shared dimension: left columns and right rows
     * @param positive-int $columns Number of columns of the right operand
     *
     * @return CData Freshly allocated `double[rows * columns]` holding the product
     */
    public function multiply(CData $left, CData $right, int $rows, int $inner, int $columns): CData;

    /**
     * Multiplies every cell by a scalar value
     *
     * @param CData        $matrix  Operand cells, row-major `double[rows * columns]`
     * @param float        $value   Multiplier
     * @param positive-int $rows    Number of rows in the operand
     * @param positive-int $columns Number of columns in the operand
     *
     * @return CData Freshly allocated `double[rows * columns]` holding the scaled cells
     */
    public function multiplyByScalar(CData $matrix, float $value, int $rows, int $columns): CData;

    /**
     * Divides every cell by a scalar value
     *
     * @param CData        $matrix  Operand cells, row-major `double[rows * columns]`
     * @param float        $value   Divider
     * @param positive-int $rows    Number of rows in the operand
     * @param positive-int $columns Number of columns in the operand
     *
     * @return CData Freshly allocated `double[rows * columns]` holding the divided cells
     */
    public function divideByScalar(CData $matrix, float $value, int $rows, int $columns): CData;

    /**
     * Raises every cell to the power of a scalar value
     *
     * BLAS has no exponentiation primitive, so accelerated drivers implement this one with a loop over the cells.
     * It is part of the contract so that every operator the class overloads has a driver-level counterpart.
     *
     * @param CData        $matrix  Operand cells, row-major `double[rows * columns]`
     * @param float        $value   Exponent
     * @param positive-int $rows    Number of rows in the operand
     * @param positive-int $columns Number of columns in the operand
     *
     * @return CData Freshly allocated `double[rows * columns]` holding the exponentiated cells
     */
    public function powByScalar(CData $matrix, float $value, int $rows, int $columns): CData;
}
