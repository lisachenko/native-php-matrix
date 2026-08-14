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

use Lisachenko\NativePhpMatrix\Backend\Backends;
use Lisachenko\NativePhpMatrix\Backend\Driver;

// Probing through the registry initialises OpenCL for the very device type NATIVE_PHP_MATRIX_CL_DEVICE selects and
// runs a real kernel on it, so this skip and the test itself can never disagree. CI installs an OpenCL runtime and
// pins the device type, so a skip there means a broken image rather than a missing GPU
try {
    include __DIR__ . '/../../../vendor/autoload.php';
    $isAvailable = in_array(Driver::Clblast->value, Backends::available(), true);
} catch (Throwable) {
    $isAvailable = false;
}

// PHPUnit prints everything after "skip" minus two characters, assuming a "- " separator: keep it there
if (!$isAvailable) {
    echo 'skip - CLBlast with a usable OpenCL device is not available in this environment';
}
