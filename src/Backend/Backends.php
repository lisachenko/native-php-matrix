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

use InvalidArgumentException;
use Throwable;

/**
 * Registry of matrix arithmetic drivers and the policy that picks one
 *
 * Drivers are registered under a short name with a lazy factory, so requiring this package never loads a shared
 * library. Selection happens once, in userland: either through {@see self::use()} or from the
 * `NATIVE_PHP_MATRIX_BACKEND` environment variable read while booting. Both validate eagerly and throw a
 * catchable exception, which is the entire point — an invalid selection discovered later, inside the operator
 * hooks, would surface as an engine-level fatal error instead.
 *
 * Built-in drivers are addressed by a {@see Driver} case, third-party ones by the string they were registered
 * under; every method that names a driver accepts either.
 *
 * The default selection is {@see Driver::Auto}, and now that matrices are stored as native float64 buffers its
 * rules fit in two lines:
 *
 * - an accelerated CPU driver probed successfully → that driver, for **every** operation. There is no longer a
 *   marshalling cost that could make an element-wise operation cheaper in the interpreter, and no integer
 *   semantics left to protect;
 * - nothing available → the pure-PHP driver, which needs no library and computes the same values.
 *
 * A GPU driver is still never chosen automatically: moving data across a bus is a decision, not a default.
 */
final class Backends
{
    /**
     * Name of the environment variable that pins the driver for the whole process
     */
    public const string ENVIRONMENT_VARIABLE = 'NATIVE_PHP_MATRIX_BACKEND';

    /**
     * Registered driver factories, keyed by driver name
     *
     * Null until the built-in drivers are registered, which happens on first use.
     *
     * @var array<string, callable(): BackendInterface>|null
     */
    private static ?array $factories = null;

    /**
     * Instantiated drivers, keyed by driver name
     *
     * @var array<string, BackendInterface>
     */
    private static array $instances = [];

    /**
     * Cached result of the availability probe of every driver that has been asked about
     *
     * @var array<string, bool>
     */
    private static array $availability = [];

    /**
     * Name of the currently selected driver, or the value of {@see Driver::Auto}
     */
    private static string $selected = Driver::Auto->value;

    /**
     * Driver that automatic routing resolved to, remembered for the process
     */
    private static ?BackendInterface $automaticBackend = null;

    /**
     * Selects the driver to use for every following operation
     *
     * @param Driver|string $driver Built-in driver, a registered third-party name, or {@see Driver::Auto}
     *
     * @throws InvalidArgumentException     When no driver is registered under that name
     * @throws BackendNotAvailableException When the driver is known but unusable in this environment
     */
    public static function use(Driver|string $driver): void
    {
        $name = Driver::nameOf($driver);
        if ($name === Driver::Auto->value) {
            self::$selected = $name;

            return;
        }

        $factories = self::factories();
        if (!isset($factories[$name])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown matrix backend "%s", registered ones are: %s',
                $name,
                implode(', ', self::registered()),
            ));
        }
        if (!self::probe($name)) {
            throw new BackendNotAvailableException(sprintf(
                'Matrix backend "%s" is registered but not available in this environment',
                $name,
            ));
        }

        self::$selected = $name;
    }

    /**
     * Registers a third-party driver under the given name, replacing an earlier one
     *
     * The factory is called at most once, and only when the driver is actually selected or probed, therefore this
     * method never throws: a driver that cannot load reports it from {@see BackendInterface::isAvailable()}.
     *
     * @param Driver|string                $driver  Name usable in {@see self::use()} and in the env variable
     * @param callable(): BackendInterface $factory Lazy factory producing the driver
     */
    public static function register(Driver|string $driver, callable $factory): void
    {
        $name             = Driver::nameOf($driver);
        $factories        = self::factories();
        $factories[$name] = $factory;
        self::$factories  = $factories;

        unset(self::$instances[$name], self::$availability[$name]);
        self::$automaticBackend = null;
    }

    /**
     * Returns the current selection
     *
     * Built-in selections come back as the matching {@see Driver} case, a third-party one as the string it was
     * registered under — the same shape {@see self::use()} accepts.
     */
    public static function active(): Driver|string
    {
        return Driver::resolveName(self::$selected);
    }

    /**
     * Returns the names of every registered driver, whether usable here or not
     *
     * @return list<string>
     */
    public static function registered(): array
    {
        return array_keys(self::factories());
    }

    /**
     * Returns the names of the drivers that are usable in this environment
     *
     * Probing loads the libraries and runs a minimal real operation with each of them, so the answer cannot
     * disagree with what an operation would do a moment later. Results are cached for the process.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        $available = [];
        foreach (self::registered() as $name) {
            if (self::probe($name)) {
                $available[] = $name;
            }
        }

        return $available;
    }

    /**
     * Restores the pristine state: automatic routing and the built-in drivers only
     *
     * Third-party registrations, driver instances and cached probe results are dropped.
     */
    public static function reset(): void
    {
        self::$factories        = null;
        self::$instances        = [];
        self::$availability     = [];
        self::$selected         = Driver::Auto->value;
        self::$automaticBackend = null;
    }

    /**
     * Applies the selection pinned by the environment, if there is one
     *
     * Called from the package bootstrap — that is ordinary userland code running long before any operator hook, so
     * a bad value fails loudly and catchably right where the environment is wrong.
     *
     * @throws InvalidArgumentException     When the variable names an unknown driver
     * @throws BackendNotAvailableException When the named driver is unusable in this environment
     */
    public static function bootFromEnvironment(): void
    {
        $name = getenv(self::ENVIRONMENT_VARIABLE);
        if (!is_string($name) || trim($name) === '') {
            return;
        }

        // A built-in name arrives as its case, anything else stays a string so that a driver registered by the
        // application is just as selectable from the environment as the ones shipped here
        self::use(Driver::resolveName(trim($name)));
    }

    /**
     * Returns the driver that must carry out an operation
     *
     * This is the hot path of every overloaded operator and therefore never throws: an explicit selection was
     * validated when it was made, and automatic routing has the always-available pure-PHP driver to fall back on.
     */
    public static function resolve(): BackendInterface
    {
        if (self::$selected !== Driver::Auto->value) {
            return self::instance(self::$selected);
        }

        return self::$automaticBackend ??= self::resolveAutomaticBackend();
    }

    /**
     * Picks the driver automatic routing uses
     *
     * Only CPU drivers take part — sending data to a GPU is a decision, not a default — and the winner is wrapped
     * so that a hardware failure at operation time degrades into a pure-PHP recomputation instead of a fatal
     * error inside an engine hook.
     */
    private static function resolveAutomaticBackend(): BackendInterface
    {
        $php = self::instance(Driver::Php->value);
        if (self::probe(Driver::Blas->value)) {
            return new FallbackBackend(self::instance(Driver::Blas->value), $php);
        }

        return $php;
    }

    /**
     * Runs the availability probe of a driver once and remembers the answer
     *
     * Every failure mode — a missing factory, a factory that blows up, a library that is not installed — collapses
     * into "not available", because this question is asked in contexts that must not fail.
     *
     * @param string $name Registered driver name
     */
    private static function probe(string $name): bool
    {
        if (isset(self::$availability[$name])) {
            return self::$availability[$name];
        }
        if (!isset(self::factories()[$name])) {
            return self::$availability[$name] = false;
        }

        try {
            $available = self::instance($name)->isAvailable();
        } catch (Throwable) {
            $available = false;
        }

        return self::$availability[$name] = $available;
    }

    /**
     * Returns the driver registered under the given name, creating it on first request
     *
     * @param string $name Registered driver name
     */
    private static function instance(string $name): BackendInterface
    {
        if (isset(self::$instances[$name])) {
            return self::$instances[$name];
        }

        $factory = self::factories()[$name] ?? null;
        if ($factory === null) {
            // Unknown names cannot reach the operators: use() and bootFromEnvironment() reject them upfront, so
            // the pure-PHP driver is the only sensible answer left for a name that disappeared from the registry
            return self::$instances[$name] = new PhpBackend();
        }

        return self::$instances[$name] = $factory();
    }

    /**
     * Returns the factory table, registering the built-in drivers on first access
     *
     * @return array<string, callable(): BackendInterface>
     */
    private static function factories(): array
    {
        if (self::$factories === null) {
            self::$factories = [
                Driver::Php->value     => static fn(): BackendInterface => new PhpBackend(),
                Driver::Blas->value    => static fn(): BackendInterface => new BlasBackend(),
                Driver::Clblast->value => static fn(): BackendInterface => new ClblastBackend(),
            ];
        }

        return self::$factories;
    }
}
