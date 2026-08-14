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
 * Contract of an interchangeable matrix arithmetic driver
 *
 * A backend is a numeric kernel and nothing else: it receives the operands as raw row-major `double[]` buffers
 * with the dimensions already known, and returns a freshly allocated buffer of the same kind. Validation,
 * dimension checks and the object identity remain the responsibility of
 * {@see \Lisachenko\NativePhpMatrix\Matrix}, so a driver never has to construct one.
 *
 * The operands are the buffers the matrices are actually stored in, handed over without a copy. That is the whole
 * point of the shape: a PHP array would have to be packed into contiguous doubles before any kernel could touch
 * it and unpacked afterwards, which for an element-wise operation costs more than the arithmetic itself. Two
 * obligations come with the privilege:
 *
 * - **Operands are read-only.** They belong to the matrices the caller still holds. A kernel that accumulates
 *   into one of its arguments — `daxpy` and `dscal` both do — copies it into the result buffer first
 *   ({@see Float64Buffer::copyOf()}) and works on the copy.
 * - **The result is a fresh allocation.** Never return an operand, and never return a buffer that outlives the
 *   call in some driver-owned cache: the returned buffer becomes the storage of a new matrix.
 *
 * Two rules bind every implementation:
 *
 * - **Hook safety.** These methods are reached from the `do_operation` handler, which runs inside an FFI callback
 *   where a thrown exception becomes an engine-level fatal error. Report an unusable driver from
 *   {@see self::isAvailable()} — which must swallow its own failures and return false — instead of throwing from
 *   an operation. The registry validates a selection eagerly, in ordinary userland code, for the same reason.
 * - **Everything is float64.** There is no integer path left to preserve: a matrix stores double precision cells,
 *   every driver reads and writes double precision cells, and the pure-PHP driver produces bit-identical results
 *   to the accelerated ones for values that are exactly representable.
 */
interface BackendInterface
{
    /**
     * Tells whether this driver can be used in the current environment
     *
     * Implementations probe their libraries here — loading them and running a real, minimal operation, so that a
     * missing symbol is discovered now rather than inside an engine hook. Failures are swallowed: an unusable
     * driver reports false, it never throws.
     */
    public function isAvailable(): bool;

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
