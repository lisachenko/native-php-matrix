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
 * The storage every matrix and every driver in this package speaks: a flat, row-major block of float64 cells
 *
 * A `double[rows * columns]` allocation is what a BLAS kernel, an OpenCL buffer and an interpreted loop can all
 * read without a conversion in between. Keeping matrices in that shape from the moment they are constructed is
 * what removes the pack/unpack step that used to be charged to every single operation.
 *
 * The buffers are allocated through the plain `FFI::new()`, not through a driver's own binding, on purpose: a
 * `double` is a `double` in every FFI type context, so a buffer allocated here is accepted by the CBLAS entry
 * points of one driver and by the OpenCL transfer calls of another, and the pure-PHP driver — which loads no
 * library at all — can allocate its results the same way. The allocation is owned by PHP, so it is released with
 * the handle and no matrix ever frees host memory by hand.
 */
final class Float64Buffer
{
    /**
     * Size of a single cell in bytes, resolved once from the running platform rather than assumed
     */
    private static ?int $cellSize = null;

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
        return FFI::new('double[' . $count . ']');
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
        self::$cellSize ??= FFI::sizeof(FFI::new('double'));

        /** @var positive-int */
        return $count * self::$cellSize;
    }
}
