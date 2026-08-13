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
 * Contract of an interchangeable matrix arithmetic driver
 *
 * A backend is a numeric kernel and nothing else: it receives plain arrays with the dimensions already known and
 * returns a plain array of the same shape. Validation, dimension checks and the object identity remain the
 * responsibility of {@see \Lisachenko\NativePhpMatrix\Matrix}, so a driver never has to construct one.
 *
 * Two rules bind every implementation:
 *
 * - **Hook safety.** These methods are reached from the `do_operation` handler, which runs inside an FFI callback
 *   where a thrown exception becomes an engine-level fatal error. Report an unusable driver from
 *   {@see self::isAvailable()} — which must swallow its own failures and return false — instead of throwing from
 *   an operation. The registry validates a selection eagerly, in ordinary userland code, for the same reason.
 * - **Accelerated drivers are float-only.** Hardware kernels compute in double precision, so a driver that is not
 *   the pure-PHP one casts integer input to float and returns floats. Automatic routing takes that into account
 *   and keeps all-integer arithmetic on the pure-PHP driver.
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
     * @param non-empty-list<non-empty-list<int|float>> $left    Left operand cells
     * @param non-empty-list<non-empty-list<int|float>> $right   Right operand cells, same shape as the left one
     * @param positive-int                              $rows    Number of rows in both operands
     * @param positive-int                              $columns Number of columns in both operands
     *
     * @return non-empty-list<non-empty-list<int|float>> Sum of both operands
     */
    public function sum(array $left, array $right, int $rows, int $columns): array;

    /**
     * Subtracts the right matrix from the left one element-wise
     *
     * @param non-empty-list<non-empty-list<int|float>> $left    Left operand cells
     * @param non-empty-list<non-empty-list<int|float>> $right   Right operand cells, same shape as the left one
     * @param positive-int                              $rows    Number of rows in both operands
     * @param positive-int                              $columns Number of columns in both operands
     *
     * @return non-empty-list<non-empty-list<int|float>> Difference of both operands
     */
    public function subtract(array $left, array $right, int $rows, int $columns): array;

    /**
     * Multiplies two matrices with matching inner dimensions
     *
     * @param non-empty-list<non-empty-list<int|float>> $left    Left operand cells, shaped rows × inner
     * @param non-empty-list<non-empty-list<int|float>> $right   Right operand cells, shaped inner × columns
     * @param positive-int                              $rows    Number of rows of the left operand
     * @param positive-int                              $inner   Shared dimension: left columns and right rows
     * @param positive-int                              $columns Number of columns of the right operand
     *
     * @return non-empty-list<non-empty-list<int|float>> Product, shaped rows × columns
     */
    public function multiply(array $left, array $right, int $rows, int $inner, int $columns): array;

    /**
     * Multiplies every cell by a scalar value
     *
     * @param non-empty-list<non-empty-list<int|float>> $matrix  Operand cells
     * @param int|float                                 $value   Multiplier
     * @param positive-int                              $rows    Number of rows in the operand
     * @param positive-int                              $columns Number of columns in the operand
     *
     * @return non-empty-list<non-empty-list<int|float>> Scaled cells
     */
    public function multiplyByScalar(array $matrix, int|float $value, int $rows, int $columns): array;

    /**
     * Divides every cell by a scalar value
     *
     * @param non-empty-list<non-empty-list<int|float>> $matrix  Operand cells
     * @param int|float                                 $value   Divider
     * @param positive-int                              $rows    Number of rows in the operand
     * @param positive-int                              $columns Number of columns in the operand
     *
     * @return non-empty-list<non-empty-list<int|float>> Divided cells
     */
    public function divideByScalar(array $matrix, int|float $value, int $rows, int $columns): array;

    /**
     * Raises every cell to the power of a scalar value
     *
     * BLAS has no exponentiation primitive, so accelerated drivers implement this one with a float loop. It is
     * part of the contract only to keep their float-only promise for every operator the class overloads.
     *
     * @param non-empty-list<non-empty-list<int|float>> $matrix  Operand cells
     * @param int|float                                 $value   Exponent
     * @param positive-int                              $rows    Number of rows in the operand
     * @param positive-int                              $columns Number of columns in the operand
     *
     * @return non-empty-list<non-empty-list<int|float>> Exponentiated cells
     */
    public function powByScalar(array $matrix, int|float $value, int $rows, int $columns): array;
}
