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

use Native\Type\Matrix;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass as ReflectionClassEx;

// We can not be sure that ZEngine library will be initialized first, so check if it present
if (!class_exists(Core::class, false)) {
    Core::init();
}

// Activate extensions for the Matrix class as it provides
$matrixClassReflection = new ReflectionClassEx(Matrix::class);
$matrixClassReflection->installExtensionHandlers();
