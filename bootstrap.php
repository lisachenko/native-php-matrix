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

use Lisachenko\NativePhpMatrix\Matrix;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass as ReflectionClassEx;

// We can not be sure that the Z-Engine library was already initialized by another package, so probe the engine state
// instead of the class existence: Core::$executor is a typed static property that is assigned only by Core::init(),
// therefore an uninitialized property means that nobody has booted the engine yet.
if (!isset(Core::$executor)) {
    Core::init();
}

// Activate extensions for the Matrix class as it provides
$matrixClassReflection = new ReflectionClassEx(Matrix::class);
$matrixClassReflection->installExtensionHandlers();
