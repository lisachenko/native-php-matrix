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
 * Storage contract of a driver that computes on PHP arrays
 *
 * The interpreter reads and writes the elements of a packed PHP array faster than it reads and writes the cells of
 * an `FFI\CData` buffer — every buffer access goes through FFI's type machinery, an array access does not. A driver
 * whose arithmetic is interpreted PHP therefore declares this contract, and the matrices built while it is active
 * keep their cells as lists of rows of floats, ready to be walked by `foreach`.
 *
 * The operands are the arrays the matrices are stored in. PHP copies arrays on write, so a driver cannot damage
 * them by accident, and the returned rows become the storage of the result as they are. They must keep the
 * float64 promise: every cell a float, never an int — a driver that starts an accumulator at `0` instead of `0.0`
 * would leak an integer into a matrix that is documented to hold none.
 */
interface ArrayBackendInterface extends BackendInterface
{
    /**
     * Adds two matrices of the same shape element-wise
     *
     * @param non-empty-list<non-empty-list<float>> $left    Left operand rows
     * @param non-empty-list<non-empty-list<float>> $right   Right operand rows, same shape as the left one
     * @param positive-int                          $rows    Number of rows in both operands
     * @param positive-int                          $columns Number of columns in both operands
     *
     * @return non-empty-list<non-empty-list<float>> Sum of both operands
     */
    public function sum(array $left, array $right, int $rows, int $columns): array;

    /**
     * Subtracts the right matrix from the left one element-wise
     *
     * @param non-empty-list<non-empty-list<float>> $left    Left operand rows
     * @param non-empty-list<non-empty-list<float>> $right   Right operand rows, same shape as the left one
     * @param positive-int                          $rows    Number of rows in both operands
     * @param positive-int                          $columns Number of columns in both operands
     *
     * @return non-empty-list<non-empty-list<float>> Difference of both operands
     */
    public function subtract(array $left, array $right, int $rows, int $columns): array;

    /**
     * Multiplies two matrices with matching inner dimensions
     *
     * @param non-empty-list<non-empty-list<float>> $left    Left operand rows, shaped rows × inner
     * @param non-empty-list<non-empty-list<float>> $right   Right operand rows, shaped inner × columns
     * @param positive-int                          $rows    Number of rows of the left operand
     * @param positive-int                          $inner   Shared dimension: left columns and right rows
     * @param positive-int                          $columns Number of columns of the right operand
     *
     * @return non-empty-list<non-empty-list<float>> Product, shaped rows × columns
     */
    public function multiply(array $left, array $right, int $rows, int $inner, int $columns): array;

    /**
     * Multiplies every cell by a scalar value
     *
     * @param non-empty-list<non-empty-list<float>> $matrix  Operand rows
     * @param float                                 $value   Multiplier
     * @param positive-int                          $rows    Number of rows in the operand
     * @param positive-int                          $columns Number of columns in the operand
     *
     * @return non-empty-list<non-empty-list<float>> Scaled cells
     */
    public function multiplyByScalar(array $matrix, float $value, int $rows, int $columns): array;

    /**
     * Divides every cell by a scalar value
     *
     * @param non-empty-list<non-empty-list<float>> $matrix  Operand rows
     * @param float                                 $value   Divider
     * @param positive-int                          $rows    Number of rows in the operand
     * @param positive-int                          $columns Number of columns in the operand
     *
     * @return non-empty-list<non-empty-list<float>> Divided cells
     */
    public function divideByScalar(array $matrix, float $value, int $rows, int $columns): array;

    /**
     * Raises every cell to the power of a scalar value
     *
     * @param non-empty-list<non-empty-list<float>> $matrix  Operand rows
     * @param float                                 $value   Exponent
     * @param positive-int                          $rows    Number of rows in the operand
     * @param positive-int                          $columns Number of columns in the operand
     *
     * @return non-empty-list<non-empty-list<float>> Exponentiated cells
     */
    public function powByScalar(array $matrix, float $value, int $rows, int $columns): array;
}
