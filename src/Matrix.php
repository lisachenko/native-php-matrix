<?php
/**
 * Native matrix library
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */
declare(strict_types = 1);

namespace Native\Type;

use InvalidArgumentException;
use ZEngine\ClassExtension\Hook\CompareValuesHook;
use ZEngine\ClassExtension\Hook\DoOperationHook;
use ZEngine\ClassExtension\ObjectCompareValuesInterface;
use ZEngine\ClassExtension\ObjectCreateInterface;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\ClassExtension\ObjectDoOperationInterface;
use ZEngine\System\OpCode;
use function count, is_numeric;

/**
 * Simple class Matrix powered by custom operator handlers
 */
class Matrix implements ObjectCreateInterface, ObjectDoOperationInterface, ObjectCompareValuesInterface
{
    use ObjectCreateTrait;

    private array $matrix = [];
    private int $rows = 0;
    private int $columns = 0;

    /**
     * Matrix constructor.
     *
     * @param array $matrix
     */
    public function __construct(array $matrix)
    {
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
     * Performs multiplication of two matrices
     *
     * @param Matrix $multiplier
     *
     * @todo: Implement SSE/AVX support for multiplication
     *
     * @return $this Product of two matrices
     */
    public function multiply(self $multiplier): self
    {
        if ($this->columns !== $multiplier->rows) {
            throw new InvalidArgumentException('Inconsistent matrix supplied');
        }

        $totalColumns = $multiplier->columns;
        $result       = [];
        foreach ($this->matrix as $row => $rowItems) {
            for ($column = 0; $column < $totalColumns; ++$column) {
                $columnItems = array_column($multiplier->matrix, $column);
                $cellValue   = 0;
                foreach ($rowItems as $key => $value) {
                    $cellValue += $value * $columnItems[$key];
                }

                $result[$row][$column] = $cellValue;
            }
        }

        return new static($result);
    }

    /**
     * Performs division by scalar value
     *
     * @param int|float $value Divider
     *
     * @return $this
     */
    public function divideByScalar($value): self
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException("Divide accepts only numeric values");
        }
        $result = [];
        for ($i = 0; $i < $this->rows; ++$i) {
            for ($j = 0; $j < $this->columns; ++$j) {
                $result[$i][$j] = $this->matrix[$i][$j] / $value;
            }
        }

        return new static($result);
    }

    /**
     * Performs multiplication by scalar value
     *
     * @param int|float $value Multiplier
     *
     * @return $this
     */
    public function multiplyByScalar($value): self
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException("Multiply accepts only numeric values");
        }
        $result = [];
        for ($i = 0; $i < $this->rows; ++$i) {
            for ($j = 0; $j < $this->columns; ++$j) {
                $result[$i][$j] = $this->matrix[$i][$j] * $value;
            }
        }

        return new static($result);
    }

    /**
     * Performs exponential expression by scalar value
     *
     * @param int|float $value Exponent
     *
     * @return $this
     */
    public function powByScalar($value): self
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException("Exponent accepts only numeric values");
        }
        $result = [];
        for ($i = 0; $i < $this->rows; ++$i) {
            for ($j = 0; $j < $this->columns; ++$j) {
                $result[$i][$j] = $this->matrix[$i][$j] ** $value;
            }
        }

        return new static($result);
    }

    /**
     * Performs addition of two matrices
     *
     * @param Matrix $value
     *
     * @return $this Sum of two matrices
     * @todo: Implement SSE/AVX support for addition
     */
    public function sum(self $value): self
    {
        $result = [];
        for ($i = 0; $i < $this->rows; ++$i) {
            for ($k = 0; $k < $this->columns; ++$k) {
                $result[$i][$k] = $this->matrix[$i][$k] + $value->matrix[$i][$k];
            }
        }

        return new static($result);
    }

    /**
     * Performs subtraction of two matrices
     *
     * @param Matrix $value
     *
     * @todo: Implement SSE/AVX support for addition
     *
     * @return $this Subtraction of two matrices
     */
    public function subtract(self $value): self
    {
        $result = [];
        for ($i = 0; $i < $this->rows; ++$i) {
            for ($k = 0; $k < $this->columns; ++$k) {
                $result[$i][$k] = $this->matrix[$i][$k] - $value->matrix[$i][$k];
            }
        }

        return new static($result);
    }

    /**
     * Checks if the given matrix equals to another one
     *
     * @param Matrix $another Another matrix
     *
     * @return bool
     */
    public function equals(Matrix $another): bool
    {
        if ($another->rows !== $this->rows || $another->columns !== $this->columns) {
            return false;
        }
        for ($i = 0; $i < $this->rows; ++$i) {
            for ($k = 0; $k < $this->columns; ++$k) {
                $equals = $this->matrix[$i][$k] === $another->matrix[$i][$k];
                if (!$equals) {
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
     * @return mixed Result of operation value
     */
    public static function __doOperation(DoOperationHook $hook): Matrix
    {
        $left   = $hook->getFirst();
        $right  = $hook->getSecond();
        $opCode = $hook->getOpcode();

        $isLeftMatrix   = $left instanceof Matrix;
        $isRightMatrix  = $right instanceof Matrix;
        $isLeftNumeric  = is_numeric($left);
        $isRightNumeric = is_numeric($right);

        switch ($opCode) {
            case OpCode::ADD:
                if ($isLeftMatrix && $isRightMatrix) {
                    return $left->sum($right);
                }
                break;
            case OpCode::SUB:
                if ($isLeftMatrix && $isRightMatrix) {
                    return $left->subtract($right);
                }
                break;
            case OpCode::MUL:
                if ($isLeftMatrix && $isRightMatrix) {
                    return $left->multiply($right);
                } elseif ($isLeftMatrix && $isRightNumeric) {
                    return $left->multiplyByScalar($right);
                } elseif ($isLeftNumeric && $isRightMatrix) {
                    return $right->multiplyByScalar($left);
                }
                break;
            case OpCode::DIV:
                if ($isLeftMatrix && $isRightNumeric) {
                    return $left->divideByScalar($right);
                }
                break;
            case OpCode::POW:
                if ($isLeftMatrix && $isRightNumeric) {
                    return $left->powByScalar($right);
                }
                break;
        }

        throw new \LogicException('Unsupported ' . OpCode::name($opCode). ' operation or invalid arguments');
    }

    /**
     * Performs comparison of given object with another value
     *
     * @param CompareValuesHook $hook Instance of current hook
     *
     * @return int Result of comparison: 1 is greater, -1 is less, 0 is equal
     */
    public static function __compare(CompareValuesHook $hook): int
    {
        $one     = $hook->getFirst();
        $another = $hook->getSecond();
        if (!($one instanceof Matrix) || !($another instanceof Matrix)) {
            throw new \InvalidArgumentException('Matrix can be compared only with another matrix');
        }

        if ($one->equals($another)) {
            return 0;
        }

        return -2;
    }
}
