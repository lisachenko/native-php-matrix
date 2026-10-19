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

use RuntimeException;

/**
 * Thrown when a known matrix backend cannot be used in the current environment
 *
 * This is a selection-time failure — it happens in ordinary userland code, either from an explicit
 * {@see Backends::use()} call or while booting the selection from the environment, and it is therefore
 * catchable. Backends never report unavailability from inside an engine hook.
 */
final class BackendNotAvailableException extends RuntimeException {}
