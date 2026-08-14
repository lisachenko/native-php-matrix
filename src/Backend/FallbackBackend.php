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
use Throwable;

/**
 * Driver decorator that degrades to another driver instead of failing an operation
 *
 * Automatic routing uses it to keep the engine hooks safe. An accelerated driver reports its availability after a
 * real probe, but hardware can still fail later — a device is reset, a queue dies, a library is unloaded — and by
 * then the call is deep inside an FFI callback where a thrown exception is an unrecoverable fatal error. Catching
 * the failure here and recomputing with the fallback keeps the operator working: catching *inside* the hook is
 * perfectly legal, only exceptions crossing the callback boundary are fatal.
 *
 * Recomputing is safe because a driver may not write to its operands: whatever the primary managed to do before
 * it failed, it did to a buffer of its own, so the fallback is handed the same untouched cells.
 *
 * This decorator is deliberately not used for an explicit selection. Asking for a specific driver and silently
 * getting another one's results, at a different speed, would hide exactly what the caller wanted to control.
 */
final class FallbackBackend implements BackendInterface
{
    public function __construct(
        private readonly BackendInterface $primary,
        private readonly BackendInterface $fallback,
    ) {}

    /**
     * Reports availability of the primary driver, the fallback is available by definition
     */
    public function isAvailable(): bool
    {
        return $this->primary->isAvailable();
    }

    /**
     * {@inheritDoc}
     */
    public function sum(CData $left, CData $right, int $rows, int $columns): CData
    {
        try {
            return $this->primary->sum($left, $right, $rows, $columns);
        } catch (Throwable) {
            return $this->fallback->sum($left, $right, $rows, $columns);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function subtract(CData $left, CData $right, int $rows, int $columns): CData
    {
        try {
            return $this->primary->subtract($left, $right, $rows, $columns);
        } catch (Throwable) {
            return $this->fallback->subtract($left, $right, $rows, $columns);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function multiply(CData $left, CData $right, int $rows, int $inner, int $columns): CData
    {
        try {
            return $this->primary->multiply($left, $right, $rows, $inner, $columns);
        } catch (Throwable) {
            return $this->fallback->multiply($left, $right, $rows, $inner, $columns);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function multiplyByScalar(CData $matrix, float $value, int $rows, int $columns): CData
    {
        try {
            return $this->primary->multiplyByScalar($matrix, $value, $rows, $columns);
        } catch (Throwable) {
            return $this->fallback->multiplyByScalar($matrix, $value, $rows, $columns);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function divideByScalar(CData $matrix, float $value, int $rows, int $columns): CData
    {
        try {
            return $this->primary->divideByScalar($matrix, $value, $rows, $columns);
        } catch (Throwable) {
            return $this->fallback->divideByScalar($matrix, $value, $rows, $columns);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function powByScalar(CData $matrix, float $value, int $rows, int $columns): CData
    {
        try {
            return $this->primary->powByScalar($matrix, $value, $rows, $columns);
        } catch (Throwable) {
            return $this->fallback->powByScalar($matrix, $value, $rows, $columns);
        }
    }
}
