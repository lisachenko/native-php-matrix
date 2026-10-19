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
 * Common root of every matrix arithmetic driver
 *
 * A backend is a numeric kernel and nothing else: it receives the operands with the dimensions already known and
 * returns freshly computed cells. Validation, dimension checks and the object identity remain the responsibility
 * of {@see \Lisachenko\NativePhpMatrix\Matrix}, so a driver never has to construct one.
 *
 * What a driver does not implement here is its **storage contract**, and it must implement exactly one:
 *
 * - {@see BufferBackendInterface} — the operands arrive as row-major `double[]` buffers and the result is one. That
 *   is the form a native library reads without a conversion, and the one the accelerated drivers declare.
 * - {@see ArrayBackendInterface} — the operands arrive as lists of rows of PHP floats and the result is one. That
 *   is the form the interpreter walks fastest, and the one the pure-PHP driver declares.
 *
 * A matrix stores its cells in the form the driver active at construction declares, so the operands of an
 * operation are normally already in the right shape. Only when the driver changed in between — an explicit switch,
 * or {@see FallbackBackend} recomputing a failed accelerated operation in PHP — is an operand converted, once, for
 * that operation. A registered driver that implements neither contract cannot be selected.
 *
 * Two rules bind every implementation, whatever its storage:
 *
 * - **Hook safety.** The operations are reached from the `do_operation` handler, which runs inside an FFI callback
 *   where a thrown exception becomes an engine-level fatal error. Report an unusable driver from
 *   {@see self::isAvailable()} — which must swallow its own failures and return false — instead of throwing from
 *   an operation. The registry validates a selection eagerly, in ordinary userland code, for the same reason.
 * - **Everything is float64.** There is no integer path to preserve: a buffer holds doubles and a row holds PHP
 *   floats, which are doubles too, and the pure-PHP driver produces bit-identical results to the accelerated ones
 *   for values that are exactly representable.
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
}
