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
 * The drivers this package ships, plus the sentinel that stands for automatic routing
 *
 * These four names used to be loose string constants on {@see Backends}, which made every selection a string
 * comparison that no analyser could check. As an enum they are a closed type: `Backends::use(Driver::Blas)` cannot
 * be misspelled, and a `match` over a driver is exhaustive.
 *
 * The registry still accepts plain strings, because {@see Backends::register()} exists precisely so that drivers
 * this package knows nothing about can be plugged in, and those names cannot be cases of this enum. The rule is
 * therefore: built-in drivers are addressed by a case, out-of-tree drivers by their registered string.
 */
enum Driver: string
{
    /**
     * Routing that picks the driver per environment rather than per call site
     */
    case Auto = 'auto';

    /**
     * The always-available driver that computes in interpreted PHP
     */
    case Php = 'php';

    /**
     * The OpenBLAS CPU driver
     */
    case Blas = 'blas';

    /**
     * The CLBlast GPU driver, never selected automatically
     */
    case Clblast = 'clblast';

    /**
     * Returns the registry name of a selection given as either a case or a third-party string
     *
     * @param self|string $driver Built-in driver, or the name a third-party driver was registered under
     */
    public static function nameOf(self|string $driver): string
    {
        return $driver instanceof self ? $driver->value : $driver;
    }

    /**
     * Returns the case matching a registry name, or the name itself when no case does
     *
     * Used wherever a name arrives as text — the environment variable, a registered driver — so that built-in
     * selections are reported as the enum while third-party ones keep their string.
     *
     * @param string $name Registry name of a driver
     */
    public static function resolveName(string $name): self|string
    {
        return self::tryFrom($name) ?? $name;
    }
}
