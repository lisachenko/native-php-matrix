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
 * The storage the accelerated drivers speak: a flat, row-major block of float64 cells
 *
 * A `double[rows * columns]` allocation is what a BLAS kernel and an OpenCL transfer read without a conversion in
 * between. A matrix built while such a driver is active is kept in that shape from the moment it is constructed,
 * which removes the pack/unpack step that would otherwise be charged to every single operation. Under the
 * pure-PHP driver a matrix keeps PHP rows instead; the two conversions between the forms live here as well, for
 * the rare operation whose operand was built under another driver.
 *
 * The buffers are allocated through a bare `FFI::cdef()` scope of their own, not through a driver's binding, on
 * purpose: a `double` is a `double` in every FFI type context, so a buffer allocated here is accepted by the CBLAS
 * entry points of one driver and by the OpenCL transfer calls of another, and the pure-PHP driver — which loads no
 * library at all — can allocate its results the same way. The scope declares nothing and binds no library; it
 * exists because calling `FFI::new()` statically is deprecated since PHP 8.3. The allocation is owned by PHP, so
 * it is released with the handle and no matrix ever frees host memory by hand.
 */
final class Float64Buffer
{
    /**
     * Size of a single cell in bytes, resolved once from the running platform rather than assumed
     */
    private static ?int $cellSize = null;

    /**
     * Empty FFI scope the buffers are allocated from, created on first use
     */
    private static ?FFI $scope = null;

    /**
     * Allocates an owned, zero-filled buffer of float64 cells
     *
     * An allocation that does not succeed cannot produce a usable matrix, so nothing is caught here: FFI raises,
     * or the declared return type rejects whatever came back instead. Under automatic routing that failure is
     * still contained, because {@see FallbackBackend} recomputes the operation on the pure-PHP driver.
     *
     * @param positive-int $count Number of cells to reserve
     *
     * @return CData Owned `double[count]` buffer
     */
    public static function allocate(int $count): CData
    {
        return self::scope()->new('double[' . $count . ']');
    }

    /**
     * Allocates a buffer holding a copy of the given one
     *
     * Kernels such as `daxpy` and `dscal` accumulate into an operand instead of writing to a separate output, so a
     * driver that must not touch the operand it was handed copies it here first. The copy is a single `memcpy`,
     * which is what makes it acceptable in the hot path.
     *
     * @param CData        $source Buffer holding at least count cells
     * @param positive-int $count  Number of cells to copy
     *
     * @return CData Owned `double[count]` buffer with the same contents
     */
    public static function copyOf(CData $source, int $count): CData
    {
        $copy = self::allocate($count);
        FFI::memcpy($copy, $source, self::bytes($count));

        return $copy;
    }

    /**
     * Tells whether two buffers hold bit-identical cells
     *
     * The comparison is a `memcmp` over the raw cells, which is stricter than `==` on the values it stands for:
     * `-0.0` does not match `0.0` although PHP considers them equal, and a `NAN` cell matches another `NAN` with
     * the same bit pattern although PHP considers no `NAN` equal to anything. Both follow from comparing storage
     * rather than numbers, and both are documented on {@see \Lisachenko\NativePhpMatrix\Matrix::equals()}.
     *
     * @param CData        $left  Left buffer
     * @param CData        $right Right buffer, holding at least as many cells
     * @param positive-int $count Number of cells to compare
     */
    public static function identical(CData $left, CData $right, int $count): bool
    {
        return FFI::memcmp($left, $right, self::bytes($count)) === 0;
    }

    /**
     * Allocates a buffer holding the cells of the given rows
     *
     * This is one of the two storage conversions, used only where a matrix stored as PHP rows meets a driver that
     * computes on buffers — never on the path of an operation whose operands already match their driver. It has no
     * loop over the cells in PHP: the rows are packed into their native byte representation and copied in bulk.
     *
     * @param non-empty-list<non-empty-list<float>> $rows  Rows of float cells
     * @param positive-int                          $count Total number of cells the rows hold
     *
     * @return CData Owned `double[count]` buffer, row-major
     */
    public static function fromRows(array $rows, int $count): CData
    {
        $buffer = self::allocate($count);
        $packed = self::packRows($rows);
        FFI::memcpy($buffer, $packed, strlen($packed));

        return $buffer;
    }

    /**
     * Returns the cells of a buffer as a list of rows of floats
     *
     * The other storage conversion, and the boundary between a buffer and ordinary PHP: the bytes are read out in
     * one call and unpacked natively, so the rows never cost a PHP-level loop over the cells either.
     *
     * @param CData        $buffer  Row-major buffer holding at least rows × columns cells
     * @param positive-int $rows    Number of rows to read
     * @param positive-int $columns Number of columns to read
     *
     * @return non-empty-list<non-empty-list<float>> Cells, row after row
     */
    public static function toRows(CData $buffer, int $rows, int $columns): array
    {
        $cells = unpack('d*', FFI::string($buffer, self::bytes($rows * $columns)));

        /** @var non-empty-list<non-empty-list<float>> */
        return array_chunk(is_array($cells) ? array_values($cells) : [], $columns);
    }

    /**
     * Tells whether two lists of rows hold bit-identical cells
     *
     * The array counterpart of {@see self::identical()}, with the same deliberately storage-level semantics: the
     * cells are compared as the doubles they are packed into, so `-0.0` does not match `0.0` and a `NAN` matches a
     * `NAN` with the same bit pattern, exactly as a `memcmp` over two buffers decides.
     *
     * @param non-empty-list<non-empty-list<float>> $left  Left rows
     * @param non-empty-list<non-empty-list<float>> $right Right rows, of the same shape
     */
    public static function identicalRows(array $left, array $right): bool
    {
        foreach ($left as $rowIndex => $row) {
            if (pack('d*', ...$row) !== pack('d*', ...$right[$rowIndex])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reads a single cell out of a buffer
     *
     * The hot loops of the drivers index their buffers directly, because a call per cell would dominate them.
     * This accessor exists for the places where clarity wins over the last nanosecond — the availability probes,
     * which touch exactly one cell — and it keeps the raw indexing they would otherwise need out of those files.
     *
     * @param CData $buffer Buffer holding the cell
     * @param int   $offset Zero-based cell index
     */
    public static function read(CData $buffer, int $offset): float
    {
        return $buffer[$offset];
    }

    /**
     * Writes a single cell into a buffer
     *
     * @param CData $buffer Buffer to write into
     * @param int   $offset Zero-based cell index
     * @param float $value  Value to store
     */
    public static function write(CData $buffer, int $offset, float $value): void
    {
        $buffer[$offset] = $value;
    }

    /**
     * Returns the number of bytes occupied by the given number of cells
     *
     * @param positive-int $count Number of cells
     *
     * @return positive-int Size in bytes
     */
    public static function bytes(int $count): int
    {
        self::$cellSize ??= FFI::sizeof(self::scope()->new('double'));

        /** @var positive-int */
        return $count * self::$cellSize;
    }

    /**
     * Packs rows of floats into their native, machine-order double representation
     *
     * @param non-empty-list<non-empty-list<float>> $rows Rows of float cells
     */
    private static function packRows(array $rows): string
    {
        $packed = '';
        foreach ($rows as $row) {
            $packed .= pack('d*', ...$row);
        }

        return $packed;
    }

    /**
     * Returns the empty FFI scope buffers are allocated from
     *
     * An empty `FFI::cdef()` still knows every built-in C type, which is all a `double[n]` allocation needs.
     */
    private static function scope(): FFI
    {
        return self::$scope ??= FFI::cdef();
    }
}
