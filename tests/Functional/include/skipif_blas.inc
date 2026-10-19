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

// The probe asks the registry rather than looking for a file: Backends::available() loads the library and runs a
// real operation with it, so a skip here can never disagree with what the test itself would do a moment later.
// CI installs every acceleration library precisely so that this never fires — a skip there means a broken image
try {
    include __DIR__ . '/../../../vendor/autoload.php';
    $isAvailable = in_array(Driver::Blas->value, Backends::available(), true);
} catch (Throwable) {
    $isAvailable = false;
}

// PHPUnit prints everything after "skip" minus two characters, assuming a "- " separator: keep it there
if (!$isAvailable) {
    echo 'skip - OpenBLAS is not available in this environment';
}
