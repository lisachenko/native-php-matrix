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

namespace Lisachenko\NativePhpMatrix;

use function array_column;
use function array_is_list;
use function array_keys;
use function count;

use InvalidArgumentException;

use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;

use LogicException;

use function sprintf;

use ZEngine\ClassExtension\Hook\CompareValuesHook;
use ZEngine\ClassExtension\Hook\DoOperationHook;
use ZEngine\ClassExtension\ObjectCompareValuesInterface;
use ZEngine\ClassExtension\ObjectCreateInterface;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\ClassExtension\ObjectDoOperationInterface;
use ZEngine\System\OpCode;

/**
 * Simple class Matrix powered by custom operator handlers
 *
 * Note about the generic parameter: arithmetic honestly widens the cell type. Even for a `Matrix<int>` a division
 * or an exponentiation may produce floats, therefore every operation returns a `Matrix<int|float>` instead of
 * pretending that the original `T` is preserved.
 *
 * @template-covariant T of int|float
 */
final class Matrix implements ObjectCreateInterface, ObjectDoOperationInterface, ObjectCompareValuesInterface
{
    use ObjectCreateTrait;

    /**
     * Matrix cells, stored as a list of rows
     *
     * @var non-empty-list<non-empty-list<T>>
     */
    private readonly array $matrix;

    /**
     * Total number of rows in this matrix
     */
    private readonly int $rows;

    /**
     * Total number of columns in this matrix
     */
    private readonly int $columns;

    /**
     * Matrix constructor
     *
     * @param non-empty-list<non-empty-list<T>> $matrix Rectangular list of rows, each one holding numeric cells
     */
    public function __construct(array $matrix)
    {
        if ($matrix === []) {
            throw new InvalidArgumentException('Matrix should contain at least one row');
        }
        if (!array_is_list($matrix)) {
            throw new InvalidArgumentException('Matrix should be a list of rows with sequential keys, starting from 0');
        }

        $columns = null;
        foreach ($matrix as $rowIndex => $row) {
            if (!is_array($row) || !array_is_list($row)) {
                throw new InvalidArgumentException(
                    sprintf('Matrix row %d should be a list of values with sequential keys, starting from 0', $rowIndex),
                );
            }
            if ($row === []) {
                throw new InvalidArgumentException('Matrix should contain at least one column');
            }
            if ($columns === null) {
                $columns = count($row);
            } elseif (count($row) !== $columns) {
                throw new InvalidArgumentException('All matrix rows should have the same number of columns');
            }
            foreach ($row as $columnIndex => $value) {
                if (!is_int($value) && !is_float($value)) {
                    throw new InvalidArgumentException(
                        sprintf('Matrix value at [%d][%d] should be either an int or a float', $rowIndex, $columnIndex),
                    );
                }
            }
        }

        $this->matrix  = $matrix;
        $this->rows    = count($matrix);
        $this->columns = count($matrix[0]);
    }

    public function getRows(): int
    {
        return $this->rows;
    }

    public function getColumns(): int
    {
        return $this->columns;
    }

    public function isSquare(): bool
    {
        return $this->columns === $this->rows;
    }

    /**
     * Returns an underlying representation of this matrix
     *
     * @return non-empty-list<non-empty-list<T>>
     */
    public function toArray(): array
    {
        return $this->matrix;
    }

    /**
     * Performs multiplication of two matrices
     *
     * @param self<int|float> $multiplier Right operand
     *
     * @return self<int|float> Product of two matrices
     */
    public function multiply(self $multiplier): self
    {
        if ($this->columns !== $multiplier->rows) {
            throw new InvalidArgumentException('Inconsistent matrix supplied');
        }

        // Columns of the multiplier are extracted only once, they are reused for every row of the left operand
        $multiplierColumns = [];
        foreach (array_keys($multiplier->matrix[0]) as $column) {
            $multiplierColumns[] = array_column($multiplier->matrix, $column);
        }

        $result = [];
        foreach ($this->matrix as $rowItems) {
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

        return new self($result);
    }

    /**
     * Performs division by scalar value
     *
     * @param int|float $value Divider
     *
     * @return self<int|float>
     */
    public function divideByScalar(int|float $value): self
    {
        $result = [];
        foreach ($this->matrix as $row) {
            $resultRow = [];
            foreach ($row as $cellValue) {
                $resultRow[] = $cellValue / $value;
            }
            $result[] = $resultRow;
        }

        return new self($result);
    }

    /**
     * Performs multiplication by scalar value
     *
     * @param int|float $value Multiplier
     *
     * @return self<int|float>
     */
    public function multiplyByScalar(int|float $value): self
    {
        $result = [];
        foreach ($this->matrix as $row) {
            $resultRow = [];
            foreach ($row as $cellValue) {
                $resultRow[] = $cellValue * $value;
            }
            $result[] = $resultRow;
        }

        return new self($result);
    }

    /**
     * Performs exponential expression by scalar value
     *
     * @param int|float $value Exponent
     *
     * @return self<int|float>
     */
    public function powByScalar(int|float $value): self
    {
        $result = [];
        foreach ($this->matrix as $row) {
            $resultRow = [];
            foreach ($row as $cellValue) {
                $resultRow[] = $cellValue ** $value;
            }
            $result[] = $resultRow;
        }

        return new self($result);
    }

    /**
     * Performs addition of two matrices
     *
     * @param self<int|float> $value Right operand
     *
     * @return self<int|float> Sum of two matrices
     */
    public function sum(self $value): self
    {
        if (($this->columns !== $value->columns) || ($this->rows !== $value->rows)) {
            throw new InvalidArgumentException('Inconsistent matrix supplied');
        }

        $result = [];
        foreach ($this->matrix as $rowIndex => $row) {
            $anotherRow = $value->matrix[$rowIndex];
            $resultRow  = [];
            foreach ($row as $columnIndex => $cellValue) {
                $resultRow[] = $cellValue + $anotherRow[$columnIndex];
            }
            $result[] = $resultRow;
        }

        return new self($result);
    }

    /**
     * Performs subtraction of two matrices
     *
     * @param self<int|float> $value Right operand
     *
     * @return self<int|float> Difference of two matrices
     */
    public function subtract(self $value): self
    {
        if (($this->columns !== $value->columns) || ($this->rows !== $value->rows)) {
            throw new InvalidArgumentException('Inconsistent matrix supplied');
        }

        $result = [];
        foreach ($this->matrix as $rowIndex => $row) {
            $anotherRow = $value->matrix[$rowIndex];
            $resultRow  = [];
            foreach ($row as $columnIndex => $cellValue) {
                $resultRow[] = $cellValue - $anotherRow[$columnIndex];
            }
            $result[] = $resultRow;
        }

        return new self($result);
    }

    /**
     * Checks if the given matrix equals to another one
     *
     * @param self<int|float> $another Another matrix
     */
    public function equals(self $another): bool
    {
        if ($another->rows !== $this->rows || $another->columns !== $this->columns) {
            return false;
        }
        foreach ($this->matrix as $rowIndex => $row) {
            $anotherRow = $another->matrix[$rowIndex];
            foreach ($row as $columnIndex => $cellValue) {
                if ($cellValue !== $anotherRow[$columnIndex]) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Performs an operation on given object
     *
     * @param DoOperationHook $hook Instance of current hook
     *
     * @return self<int|float> Result of operation
     */
    public static function __doOperation(DoOperationHook $hook): self
    {
        $left   = $hook->getFirst();
        $right  = $hook->getSecond();
        $opCode = $hook->getOpcode();

        $leftMatrix  = $left instanceof self ? $left : null;
        $rightMatrix = $right instanceof self ? $right : null;
        $leftScalar  = self::asScalar($left);
        $rightScalar = self::asScalar($right);

        switch ($opCode) {
            case OpCode::ADD:
                if ($leftMatrix !== null && $rightMatrix !== null) {
                    return $leftMatrix->sum($rightMatrix);
                }
                break;
            case OpCode::SUB:
                if ($leftMatrix !== null && $rightMatrix !== null) {
                    return $leftMatrix->subtract($rightMatrix);
                }
                break;
            case OpCode::MUL:
                if ($leftMatrix !== null && $rightMatrix !== null) {
                    return $leftMatrix->multiply($rightMatrix);
                }
                if ($leftMatrix !== null && $rightScalar !== null) {
                    return $leftMatrix->multiplyByScalar($rightScalar);
                }
                if ($leftScalar !== null && $rightMatrix !== null) {
                    return $rightMatrix->multiplyByScalar($leftScalar);
                }
                break;
            case OpCode::DIV:
                if ($leftMatrix !== null && $rightScalar !== null) {
                    return $leftMatrix->divideByScalar($rightScalar);
                }
                break;
            case OpCode::POW:
                if ($leftMatrix !== null && $rightScalar !== null) {
                    return $leftMatrix->powByScalar($rightScalar);
                }
                break;
        }

        throw new LogicException('Unsupported ' . OpCode::name($opCode) . ' operation or invalid arguments');
    }

    /**
     * Performs comparison of given object with another value
     *
     * @param CompareValuesHook $hook Instance of current hook
     *
     * @return int Result of comparison: 0 is equal, -2 is uncomparable
     */
    public static function __compare(CompareValuesHook $hook): int
    {
        $one     = $hook->getFirst();
        $another = $hook->getSecond();
        if (!($one instanceof self) || !($another instanceof self)) {
            throw new InvalidArgumentException('Matrix can be compared only with another matrix');
        }

        if ($one->equals($another)) {
            return 0;
        }

        return -2;
    }

    /**
     * Casts an operand received from the engine into a native scalar value, if possible
     *
     * Numeric strings are explicitly coerced with the unary plus, otherwise `$matrix * '2'` would fail against the
     * natively typed `int|float` parameters under `strict_types=1`.
     *
     * @param mixed $operand Raw operand, received from the operation hook
     *
     * @return int|float|null Coerced value or null if the operand is not numeric at all
     */
    private static function asScalar(mixed $operand): int|float|null
    {
        if (is_int($operand) || is_float($operand)) {
            return $operand;
        }
        if (is_string($operand) && is_numeric($operand)) {
            return +$operand;
        }

        return null;
    }
}
