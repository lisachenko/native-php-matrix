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

use FFI\CData;
use InvalidArgumentException;
use Lisachenko\NativePhpMatrix\Backend\ArrayBackendInterface;
use Lisachenko\NativePhpMatrix\Backend\Backends;
use Lisachenko\NativePhpMatrix\Backend\Float64Buffer;
use LogicException;
use ReflectionClass;
use ZEngine\ClassExtension\Hook\CastObjectHook;
use ZEngine\ClassExtension\Hook\CastType;
use ZEngine\ClassExtension\Hook\CompareValuesHook;
use ZEngine\ClassExtension\Hook\DoOperationHook;
use ZEngine\ClassExtension\Hook\GetPropertiesForHook;
use ZEngine\ClassExtension\Hook\PropertyPurpose;
use ZEngine\ClassExtension\ObjectCastInterface;
use ZEngine\ClassExtension\ObjectCompareValuesInterface;
use ZEngine\ClassExtension\ObjectCreateInterface;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\ClassExtension\ObjectDoOperationInterface;
use ZEngine\ClassExtension\ObjectGetPropertiesForInterface;
use ZEngine\System\OpCode;

/**
 * Simple class Matrix powered by custom operator handlers
 *
 * Cells are float64, always. Integers are accepted as input and stored as the doubles they convert to, the way
 * `numpy.array([[1, 2]])` yields `dtype=float64` — so `new Matrix([[1, 2]])->toArray()` gives `[[1.0, 2.0]]`, and
 * every operation returns floats whichever driver computed it.
 *
 * Where those doubles live follows the driver. Under an accelerated driver a matrix owns a single, contiguous,
 * row-major `double[rows * columns]` allocation, which a BLAS kernel or an OpenCL transfer reads as the raw
 * pointer it is: nothing is packed on the way in, nothing is unpacked on the way out, and the buffer a kernel
 * writes becomes the storage of the matrix the operator returns. Under the pure-PHP driver a matrix keeps a list of
 * rows of PHP floats instead, because the interpreter walks an array two to three times faster than it walks
 * `FFI\CData`. The form is chosen once, by the constructor, from the driver active at that moment.
 *
 * An operand whose form does not match the driver of an operation — it was built before a switch to another
 * driver — is converted once for that operation, in bulk, and the result is stored in the form of the driver that
 * computed it. Observable behaviour is the same in both forms: the cells are the same doubles.
 */
final class Matrix implements
    ObjectCastInterface,
    ObjectCompareValuesInterface,
    ObjectCreateInterface,
    ObjectDoOperationInterface,
    ObjectGetPropertiesForInterface
{
    use ObjectCreateTrait;

    /**
     * Reflection used to build a matrix around cells a driver has just computed
     *
     * Resolved once for the process. Bypassing the constructor is not an optimisation trick here but the point:
     * the cells of a computed result are already valid float64 in the right layout, and re-validating them would
     * mean reading every one of them back into PHP.
     *
     * @var ReflectionClass<self>|null
     */
    private static ?ReflectionClass $reflection = null;

    /**
     * Cells of this matrix, row after row, as float64
     *
     * Either a row-major `double[rows * columns]` buffer, for the drivers that compute on native memory, or a list
     * of rows of PHP floats, for the driver that computes in the interpreter — see the class docblock. A buffer is
     * owned by PHP and released together with this object, which is why no matrix ever frees host memory by hand.
     *
     * The three properties below are written exactly once — by the constructor, or by {@see self::fromCells()}
     * for a result a driver has just computed — and never again; nothing in this class mutates a matrix in place.
     * They are not declared `readonly` only because that second initialisation path assigns them from a static
     * method rather than from the constructor, which the attribute forbids.
     *
     * @var CData|non-empty-list<non-empty-list<float>>
     */
    private CData|array $cells;

    /**
     * Total number of rows in this matrix
     *
     * @var positive-int
     */
    private int $rows;

    /**
     * Total number of columns in this matrix
     *
     * @var positive-int
     */
    private int $columns;

    /**
     * Matrix constructor
     *
     * Validation and conversion are the same pass, in the storage form of the active driver: each cell is checked
     * and then either written straight into a freshly allocated buffer, where FFI performs the int-to-double
     * conversion natively, or appended to its row as a PHP float. There is no intermediate array and no second
     * loop over the cells in either form.
     *
     * @param non-empty-list<non-empty-list<int|float>> $matrix Rectangular list of rows, each one holding numeric cells
     *
     * @throws InvalidArgumentException When the argument is not a non-empty, rectangular list of numeric rows
     */
    public function __construct(array $matrix)
    {
        if ($matrix === []) {
            throw new InvalidArgumentException('Matrix should contain at least one row');
        }
        if (!array_is_list($matrix)) {
            throw new InvalidArgumentException('Matrix should be a list of rows with sequential keys, starting from 0');
        }

        $rows    = count($matrix);
        $columns = count(self::validateRow($matrix[0], 0));

        $this->rows    = $rows;
        $this->columns = $columns;

        // The registry caches its answer, so asking it for every new matrix costs a static property read
        if (Backends::resolve() instanceof ArrayBackendInterface) {
            $this->cells = self::validateIntoRows($matrix, $columns);

            return;
        }

        $buffer = Float64Buffer::allocate($rows * $columns);
        $offset = 0;
        foreach ($matrix as $rowIndex => $row) {
            foreach (self::validateCells($row, $rowIndex, $columns) as $columnIndex => $cellValue) {
                if (!is_int($cellValue) && !is_float($cellValue)) {
                    throw self::invalidCell($rowIndex, $columnIndex);
                }
                // Assigning into a double cell is where an int becomes a float, natively
                $buffer[$offset++] = $cellValue;
            }
        }

        $this->cells = $buffer;
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
     * Returns the cells of this matrix as a list of rows
     *
     * A matrix stored as rows hands them out as they are — PHP copies an array on write, so the caller cannot
     * reach the storage through it. A buffer is unpacked on demand: that is the boundary between native memory and
     * ordinary PHP, so a caller that only wants to compute never pays for it.
     *
     * @return non-empty-list<non-empty-list<float>> Cells, row after row
     */
    public function toArray(): array
    {
        return $this->asRows();
    }

    /**
     * Performs multiplication of two matrices
     *
     * @param self $multiplier Right operand
     *
     * @return self Product of two matrices
     *
     * @throws InvalidArgumentException When the inner dimensions do not match
     */
    public function multiply(self $multiplier): self
    {
        if ($this->columns !== $multiplier->rows) {
            throw new InvalidArgumentException('Inconsistent matrix supplied');
        }

        $backend = Backends::resolve();
        if ($backend instanceof ArrayBackendInterface) {
            return self::fromCells(
                $backend->multiply($this->asRows(), $multiplier->asRows(), $this->rows, $this->columns, $multiplier->columns),
                $this->rows,
                $multiplier->columns,
            );
        }

        return self::fromCells(
            $backend->multiply($this->asBuffer(), $multiplier->asBuffer(), $this->rows, $this->columns, $multiplier->columns),
            $this->rows,
            $multiplier->columns,
        );
    }

    /**
     * Performs division by scalar value
     *
     * @param int|float $value Divider
     */
    public function divideByScalar(int|float $value): self
    {
        $backend = Backends::resolve();
        if ($backend instanceof ArrayBackendInterface) {
            return self::fromCells(
                $backend->divideByScalar($this->asRows(), (float) $value, $this->rows, $this->columns),
                $this->rows,
                $this->columns,
            );
        }

        return self::fromCells(
            $backend->divideByScalar($this->asBuffer(), (float) $value, $this->rows, $this->columns),
            $this->rows,
            $this->columns,
        );
    }

    /**
     * Performs multiplication by scalar value
     *
     * @param int|float $value Multiplier
     */
    public function multiplyByScalar(int|float $value): self
    {
        $backend = Backends::resolve();
        if ($backend instanceof ArrayBackendInterface) {
            return self::fromCells(
                $backend->multiplyByScalar($this->asRows(), (float) $value, $this->rows, $this->columns),
                $this->rows,
                $this->columns,
            );
        }

        return self::fromCells(
            $backend->multiplyByScalar($this->asBuffer(), (float) $value, $this->rows, $this->columns),
            $this->rows,
            $this->columns,
        );
    }

    /**
     * Performs exponential expression by scalar value
     *
     * @param int|float $value Exponent
     */
    public function powByScalar(int|float $value): self
    {
        $backend = Backends::resolve();
        if ($backend instanceof ArrayBackendInterface) {
            return self::fromCells(
                $backend->powByScalar($this->asRows(), (float) $value, $this->rows, $this->columns),
                $this->rows,
                $this->columns,
            );
        }

        return self::fromCells(
            $backend->powByScalar($this->asBuffer(), (float) $value, $this->rows, $this->columns),
            $this->rows,
            $this->columns,
        );
    }

    /**
     * Performs addition of two matrices
     *
     * @param self $value Right operand
     *
     * @return self Sum of two matrices
     *
     * @throws InvalidArgumentException When the dimensions do not match
     */
    public function sum(self $value): self
    {
        if (($this->columns !== $value->columns) || ($this->rows !== $value->rows)) {
            throw new InvalidArgumentException('Inconsistent matrix supplied');
        }

        $backend = Backends::resolve();
        if ($backend instanceof ArrayBackendInterface) {
            return self::fromCells(
                $backend->sum($this->asRows(), $value->asRows(), $this->rows, $this->columns),
                $this->rows,
                $this->columns,
            );
        }

        return self::fromCells(
            $backend->sum($this->asBuffer(), $value->asBuffer(), $this->rows, $this->columns),
            $this->rows,
            $this->columns,
        );
    }

    /**
     * Performs subtraction of two matrices
     *
     * @param self $value Right operand
     *
     * @return self Difference of two matrices
     *
     * @throws InvalidArgumentException When the dimensions do not match
     */
    public function subtract(self $value): self
    {
        if (($this->columns !== $value->columns) || ($this->rows !== $value->rows)) {
            throw new InvalidArgumentException('Inconsistent matrix supplied');
        }

        $backend = Backends::resolve();
        if ($backend instanceof ArrayBackendInterface) {
            return self::fromCells(
                $backend->subtract($this->asRows(), $value->asRows(), $this->rows, $this->columns),
                $this->rows,
                $this->columns,
            );
        }

        return self::fromCells(
            $backend->subtract($this->asBuffer(), $value->asBuffer(), $this->rows, $this->columns),
            $this->rows,
            $this->columns,
        );
    }

    /**
     * Checks if the given matrix equals to another one
     *
     * The cells are compared as storage: a single `memcmp` over two buffers, the packed doubles of each row for
     * two lists of rows, and a matrix of each form converts the rows side into a buffer first. All three are
     * bit-exact, and deliberately stricter than `==` on the numbers the bits stand for in two corner cases:
     * a `-0.0` cell does not match a `0.0` one, and two `NAN` cells with the same bit pattern do match. Both
     * follow from comparing memory, and neither can arise from the integral values these matrices usually hold.
     *
     * @param self $another Another matrix
     */
    public function equals(self $another): bool
    {
        if ($another->rows !== $this->rows || $another->columns !== $this->columns) {
            return false;
        }
        if (is_array($this->cells) && is_array($another->cells)) {
            return Float64Buffer::identicalRows($this->cells, $another->cells);
        }

        return Float64Buffer::identical($this->asBuffer(), $another->asBuffer(), $this->rows * $this->columns);
    }

    /**
     * Performs an operation on given object
     *
     * @param DoOperationHook $hook Instance of current hook
     *
     * @return self Result of operation
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
     * Performs casting of this object to another type, requested by the engine
     *
     * Unlike the operation and comparison hooks this handler never throws: it runs inside an FFI
     * callback, and PHP escalates any exception crossing that boundary into an engine-level
     * fatal error. Cast types that are not implemented here defer to the default engine behaviour
     * via {@see CastObjectHook::proceed()} — on the z-engine release lines this package tracks that
     * fall-through behaves exactly like an uninstalled handler, so failed numeric casts propagate
     * to the engine caller, which emits its own warning and substitutes the value 1.
     *
     * @param CastObjectHook $hook Instance of current hook
     *
     * @return mixed Casted value
     */
    public static function __cast(CastObjectHook $hook): mixed
    {
        $object = $hook->getObject();

        if ($object instanceof self) {
            switch ($hook->getCastTypeEnum()) {
                case CastType::Array:
                    // PHP 8.4 routes `(array)` casts through the get_properties_for handler (see
                    // __getFields), the branch stays for engine paths that pass IS_ARRAY directly
                    return $object->toArray();
                case CastType::String:
                    return $object->toString();
                case CastType::Bool:
                    // A valid matrix holds at least one cell by construction, so it is never "empty"
                    return true;
                default:
                    break;
            }
        }

        $hook->proceed();

        return $hook->getResult();
    }

    /**
     * Returns an array representation of this object for the purpose requested by the engine
     *
     * PHP 8.4 does not route `(array)` casts through the cast_object handler: they arrive here,
     * at the get_properties_for handler, with the ARRAY_CAST purpose. Every other purpose
     * (debugging, serialization, var_export, JSON encoding, get_object_vars) keeps the default
     * property table, reproduced with get_mangled_object_vars() because the raw hashtable
     * returned by the original engine handler cannot cross the hook boundary as a PHP array.
     * This handler runs in non-throwing engine contexts and therefore never throws.
     *
     * One deliberate deviation: get_object_vars() is scope-sensitive by default (a closure bound
     * to Matrix would see the private properties), but the calling scope is not recoverable from
     * inside this FFI callback, so every caller receives the public view — an empty array.
     *
     * @param GetPropertiesForHook $hook Instance of current hook
     *
     * @return array<array-key, mixed> Key-value pairs for the requested purpose
     */
    public static function __getFields(GetPropertiesForHook $hook): array
    {
        $object  = $hook->getObject();
        $purpose = $hook->getPurposeEnum();

        if ($object instanceof self && $purpose === PropertyPurpose::ArrayCast) {
            return $object->toArray();
        }

        if ($purpose === PropertyPurpose::GetObjectVars) {
            // The engine hands the returned table to get_object_vars() callers without applying
            // any visibility filtering once a custom handler is installed, so only the publicly
            // visible entries may be exposed here: every property of Matrix is private, exactly
            // like the default handlers would show to an outside caller. Class-scoped callers
            // lose their privileged view — see the deviation note in the method docblock
            return array_filter(
                get_mangled_object_vars($object),
                static fn(int|string $key): bool => !is_string($key) || !str_starts_with($key, "\0"),
                ARRAY_FILTER_USE_KEY,
            );
        }

        return get_mangled_object_vars($object);
    }

    /**
     * Builds a matrix around cells a driver has just computed
     *
     * Every operation ends here: a driver returns the cells it computed, in its own storage form, and they become
     * the storage of the result as they are — a buffer without a single cell crossing back into PHP, rows without
     * a single cell being copied. The constructor is bypassed because its job — validating input and converting it
     * to float64 — is already done by construction.
     *
     * @param CData|non-empty-list<non-empty-list<float>> $cells   Cells the caller hands over ownership of
     * @param positive-int                                $rows    Number of rows the cells form
     * @param positive-int                                $columns Number of columns the cells form
     */
    private static function fromCells(CData|array $cells, int $rows, int $columns): self
    {
        self::$reflection ??= new ReflectionClass(self::class);

        $matrix          = self::$reflection->newInstanceWithoutConstructor();
        $matrix->cells   = $cells;
        $matrix->rows    = $rows;
        $matrix->columns = $columns;

        return $matrix;
    }

    /**
     * Returns the cells of this matrix as rows, converting a buffer if that is how they are stored
     *
     * @return non-empty-list<non-empty-list<float>>
     */
    private function asRows(): array
    {
        $cells = $this->cells;

        return is_array($cells) ? $cells : Float64Buffer::toRows($cells, $this->rows, $this->columns);
    }

    /**
     * Returns the cells of this matrix as a buffer, converting rows if that is how they are stored
     */
    private function asBuffer(): CData
    {
        $cells = $this->cells;

        return $cells instanceof CData ? $cells : Float64Buffer::fromRows($cells, $this->rows * $this->columns);
    }

    /**
     * Validates the constructor argument into rows of PHP floats, the storage of the pure-PHP driver
     *
     * @param non-empty-list<mixed> $matrix  Rows of the constructor argument
     * @param positive-int          $columns Number of columns every row must have
     *
     * @return non-empty-list<non-empty-list<float>>
     *
     * @throws InvalidArgumentException When a row or a cell is invalid
     */
    private static function validateIntoRows(array $matrix, int $columns): array
    {
        $result = [];
        foreach ($matrix as $rowIndex => $row) {
            $resultRow = [];
            foreach (self::validateCells($row, $rowIndex, $columns) as $columnIndex => $cellValue) {
                if (!is_int($cellValue) && !is_float($cellValue)) {
                    throw self::invalidCell($rowIndex, $columnIndex);
                }
                // Appending as a float is where an int becomes one: the single conversion this storage makes
                $resultRow[] = (float) $cellValue;
            }
            $result[] = $resultRow;
        }

        /** @var non-empty-list<non-empty-list<float>> */
        return $result;
    }

    /**
     * Checks that one row of the constructor argument has the expected shape, and returns its cells
     *
     * @param mixed        $row      Candidate row, straight out of the argument
     * @param int          $rowIndex Position of the row, for the message
     * @param positive-int $columns  Number of columns every row must have
     *
     * @return non-empty-list<mixed> Cells of the row, not validated yet
     *
     * @throws InvalidArgumentException When the row is not a list of the expected length
     */
    private static function validateCells(mixed $row, int $rowIndex, int $columns): array
    {
        $cells = self::validateRow($row, $rowIndex);
        if (count($cells) !== $columns) {
            throw new InvalidArgumentException('All matrix rows should have the same number of columns');
        }

        return $cells;
    }

    /**
     * Builds the exception for a cell that is neither an int nor a float
     *
     * @param int $rowIndex    Row of the cell
     * @param int $columnIndex Column of the cell
     */
    private static function invalidCell(int $rowIndex, int $columnIndex): InvalidArgumentException
    {
        return new InvalidArgumentException(
            sprintf('Matrix value at [%d][%d] should be either an int or a float', $rowIndex, $columnIndex),
        );
    }

    /**
     * Checks that one row of the constructor argument is a non-empty list, and returns it
     *
     * @param mixed $row      Candidate row, straight out of the argument
     * @param int   $rowIndex Position of the row, for the message
     *
     * @return non-empty-list<mixed> The row itself, once it is known to be one
     *
     * @throws InvalidArgumentException When the row is not a non-empty list
     */
    private static function validateRow(mixed $row, int $rowIndex): array
    {
        if (!is_array($row) || !array_is_list($row)) {
            throw new InvalidArgumentException(
                sprintf('Matrix row %d should be a list of values with sequential keys, starting from 0', $rowIndex),
            );
        }
        if ($row === []) {
            throw new InvalidArgumentException('Matrix should contain at least one column');
        }

        return $row;
    }

    /**
     * Returns a human-readable representation of this matrix, one row per line
     */
    private function toString(): string
    {
        $rows = [];
        foreach ($this->toArray() as $row) {
            // Integral floats stringify without a fractional part, so a matrix of whole numbers still reads
            // as "[1, 2, 3]" rather than "[1.0, 2.0, 3.0]"
            $rows[] = '[' . implode(', ', $row) . ']';
        }

        return implode("\n", $rows);
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
