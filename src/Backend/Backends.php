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

use function array_keys;
use function getenv;
use function implode;

use InvalidArgumentException;

use function is_string;
use function sprintf;

use Throwable;

use function trim;

/**
 * Registry of matrix arithmetic drivers and the policy that picks one per operation
 *
 * Drivers are registered under a short name with a lazy factory, so requiring this package never loads a shared
 * library. Selection happens once, in userland: either through {@see self::use()} or from the
 * `NATIVE_PHP_MATRIX_BACKEND` environment variable read while booting. Both validate eagerly and throw a
 * catchable exception, which is the entire point — an invalid selection discovered later, inside the operator
 * hooks, would surface as an engine-level fatal error instead.
 *
 * The default selection is `auto`, whose rules are deliberately boring and predictable:
 *
 * - an operand contains a float **and** an accelerated CPU driver probed successfully → that driver;
 * - anything else, including every all-integer operation → the pure-PHP driver, whose results are bit-identical
 *   to the ones this library produced before drivers existed;
 * - a GPU driver is never chosen automatically: moving data to a device is a decision, not a default.
 */
final class Backends
{
    /**
     * Name of the environment variable that pins the driver for the whole process
     */
    public const string ENVIRONMENT_VARIABLE = 'NATIVE_PHP_MATRIX_BACKEND';

    /**
     * Selection that routes every operation by operand types and driver availability
     */
    public const string AUTO = 'auto';

    /**
     * Name of the always-available pure PHP driver
     */
    public const string PHP = 'php';

    /**
     * Name of the OpenBLAS CPU driver
     */
    public const string BLAS = 'blas';

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
     * Currently selected driver name, or {@see self::AUTO}
     */
    private static string $selected = self::AUTO;

    /**
     * Driver that automatic routing uses for operations involving floats, resolved once per process
     */
    private static ?BackendInterface $automaticFloatBackend = null;

    /**
     * Selects the driver to use for every following operation
     *
     * @param string $name Driver name, or {@see self::AUTO} to restore automatic routing
     *
     * @throws InvalidArgumentException   When no driver is registered under that name
     * @throws BackendNotAvailableException When the driver is known but unusable in this environment
     */
    public static function use(string $name): void
    {
        if ($name === self::AUTO) {
            self::$selected = self::AUTO;

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
     * @param string                     $name    Short driver name, usable in {@see self::use()} and the env variable
     * @param callable(): BackendInterface $factory Lazy factory producing the driver
     */
    public static function register(string $name, callable $factory): void
    {
        $factories        = self::factories();
        $factories[$name] = $factory;
        self::$factories  = $factories;

        unset(self::$instances[$name], self::$availability[$name]);
        self::$automaticFloatBackend = null;
    }

    /**
     * Returns the current selection: a driver name or {@see self::AUTO}
     */
    public static function active(): string
    {
        return self::$selected;
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
        self::$factories             = null;
        self::$instances             = [];
        self::$availability          = [];
        self::$selected              = self::AUTO;
        self::$automaticFloatBackend = null;
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

        self::use(trim($name));
    }

    /**
     * Returns the driver that must carry out an operation with the given operand types
     *
     * This is the hot path of every overloaded operator and therefore never throws: an explicit selection was
     * validated when it was made, and automatic routing has the always-available pure-PHP driver to fall back on.
     *
     * @param bool $operandsContainFloats Whether any operand of the operation holds a float
     */
    public static function resolveFor(bool $operandsContainFloats): BackendInterface
    {
        if (self::$selected !== self::AUTO) {
            return self::instance(self::$selected);
        }

        // Automatic routing never sends integers to an accelerated driver, because those compute in double
        // precision and would turn an exact integer result into a float
        if (!$operandsContainFloats) {
            return self::instance(self::PHP);
        }

        return self::$automaticFloatBackend ??= self::resolveAutomaticFloatBackend();
    }

    /**
     * Picks the driver that automatic routing uses for float operands
     *
     * Only CPU drivers take part — sending data to a GPU is a decision, not a default — and the winner is wrapped
     * so that a hardware failure at operation time degrades into a pure-PHP recomputation instead of a fatal
     * error inside an engine hook.
     */
    private static function resolveAutomaticFloatBackend(): BackendInterface
    {
        $php = self::instance(self::PHP);
        if (self::probe(self::BLAS)) {
            return new FallbackBackend(self::instance(self::BLAS), $php);
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
                self::PHP  => static fn(): BackendInterface => new PhpBackend(),
                self::BLAS => static fn(): BackendInterface => new BlasBackend(),
            ];
        }

        return self::$factories;
    }
}
