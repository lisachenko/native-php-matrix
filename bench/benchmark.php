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

namespace Lisachenko\NativePhpMatrix\Bench;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function getenv;
use function getopt;
use function hrtime;
use function implode;
use function in_array;

use InvalidArgumentException;

use function is_array;
use function is_string;

use Lisachenko\NativePhpMatrix\Backend\BackendNotAvailableException;
use Lisachenko\NativePhpMatrix\Backend\Backends;
use Lisachenko\NativePhpMatrix\Matrix;

use function max;
use function mt_getrandmax;
use function mt_rand;
use function mt_srand;
use function number_format;

use const PHP_EOL;

use function php_uname;

use const PHP_VERSION;

use function preg_match;
use function printf;
use function range;
use function sort;
use function sprintf;
use function str_repeat;
use function trim;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Compares the matrix backends on the operations that dominate machine learning workloads
 *
 * The point of this script is not to prove that native code is faster than an interpreter — it is, by orders of
 * magnitude — but to show where the crossover sits for *this* library, packing and unpacking included. Every
 * measurement therefore times the whole PHP-level operation: converting the rows into a buffer, calling the
 * kernel, reading the result back and validating it into a new Matrix. That is what a caller actually pays.
 */
final class Benchmark
{
    /**
     * Matrix dimensions measured when --sizes is not given
     *
     * @var list<int>
     */
    private const array DEFAULT_SIZES = [64, 128, 256, 512];

    /**
     * Backends measured when --backends is not given
     *
     * @var list<string>
     */
    private const array DEFAULT_BACKENDS = [Backends::PHP, Backends::BLAS, Backends::CLBLAST];

    /**
     * Operations measured when --ops is not given
     *
     * @var list<string>
     */
    private const array DEFAULT_OPERATIONS = ['gemm', 'add', 'scal'];

    /**
     * Number of timed repetitions when --repeat is not given
     */
    private const int DEFAULT_REPEAT = 5;

    /**
     * @param list<positive-int> $sizes Square matrix dimensions to measure
     * @param list<string>       $backends   Backend names to measure
     * @param list<string>       $operations Operation names to measure
     * @param int                $repeat     Number of timed repetitions per combination
     * @param bool               $markdown   Whether to print a table ready to paste into the README
     * @param list<string>       $arguments  Arguments this run was started with, echoed in the report
     */
    public function __construct(
        private readonly array $sizes,
        private readonly array $backends,
        private readonly array $operations,
        private readonly int $repeat,
        private readonly bool $markdown,
        private readonly array $arguments,
    ) {}

    /**
     * Builds a benchmark from the command line arguments
     */
    public static function fromCommandLine(): self
    {
        $options = getopt('', ['sizes:', 'backends:', 'ops:', 'repeat:', 'markdown', 'help']);
        if ($options === false) {
            echo self::usage();

            exit(1);
        }
        if (isset($options['help'])) {
            echo self::usage();

            exit(0);
        }

        $sizes = [];
        foreach (self::listOption($options, 'sizes', array_map(strval(...), self::DEFAULT_SIZES)) as $raw) {
            $size = (int) $raw;
            if ($size > 0) {
                $sizes[] = $size;
            }
        }

        return new self(
            $sizes,
            self::listOption($options, 'backends', self::DEFAULT_BACKENDS),
            self::listOption($options, 'ops', self::DEFAULT_OPERATIONS),
            max(1, (int) self::scalarOption($options, 'repeat', (string) self::DEFAULT_REPEAT)),
            isset($options['markdown']),
            self::commandLineArguments(),
        );
    }

    /**
     * Returns the arguments this process was started with, without the script name
     *
     * @return list<string>
     */
    private static function commandLineArguments(): array
    {
        $arguments = [];
        $argv      = $_SERVER['argv'] ?? [];
        if (is_array($argv)) {
            foreach (array_slice($argv, 1) as $argument) {
                if (is_string($argument)) {
                    $arguments[] = $argument;
                }
            }
        }

        return $arguments;
    }

    /**
     * Runs every requested combination and prints the report
     */
    public function run(): void
    {
        $this->printEnvironment();

        /** @var array<string, array<string, float|null>> $measurements Milliseconds, keyed by "op:size" then backend */
        $measurements = [];
        $available    = [];

        foreach ($this->backends as $backend) {
            try {
                Backends::use($backend);
            } catch (BackendNotAvailableException $exception) {
                echo sprintf('Skipping backend "%s": %s', $backend, $exception->getMessage()), PHP_EOL;

                continue;
            }
            $available[] = $backend;

            foreach ($this->operations as $operation) {
                foreach ($this->sizes as $size) {
                    $milliseconds                                     = $this->measure($operation, $size);
                    $measurements[$operation . ':' . $size][$backend] = $milliseconds;
                    if (!$this->markdown) {
                        printf(
                            '%-8s %-8s %5d  %10s ms%s' . PHP_EOL,
                            $backend,
                            $operation,
                            $size,
                            number_format($milliseconds, 3),
                            $operation === 'gemm' ? sprintf('  %8s GFLOP/s', number_format($this->gflops($size, $milliseconds), 2)) : '',
                        );
                    }
                }
            }
        }

        Backends::use(Backends::AUTO);

        if ($this->markdown) {
            $this->printMarkdown($measurements, $available);
        }
    }

    /**
     * Times one operation at one size and returns the median duration in milliseconds
     *
     * A warm-up run is discarded first: it absorbs the one-off costs — the OpenCL kernels CLBlast compiles for the
     * device, the first touch of every page — that would otherwise be charged to the first measurement.
     *
     * @param string       $operation Operation name
     * @param positive-int $size      Square matrix dimension
     */
    private function measure(string $operation, int $size): float
    {
        $left  = $this->randomMatrix($size);
        $right = $this->randomMatrix($size);

        $this->execute($operation, $left, $right);

        $durations = [];
        for ($run = 0; $run < $this->repeat; $run++) {
            $start = hrtime(true);
            $this->execute($operation, $left, $right);
            $durations[] = (hrtime(true) - $start) / 1_000_000;
        }

        sort($durations);

        return $durations[(int) (count($durations) / 2)];
    }

    /**
     * Performs one operation
     *
     * @param string                $operation Operation name
     * @param Matrix<int|float>     $left      Left operand
     * @param Matrix<int|float>     $right     Right operand
     */
    private function execute(string $operation, Matrix $left, Matrix $right): void
    {
        match ($operation) {
            'gemm'  => $left * $right,
            'add'   => $left + $right,
            'scal'  => $left * 2.5,
            default => throw new InvalidArgumentException(sprintf('Unknown operation "%s"', $operation)),
        };
    }

    /**
     * Builds a square matrix of reproducible pseudo-random floats
     *
     * @param positive-int $size Square matrix dimension
     *
     * @return Matrix<float>
     */
    private function randomMatrix(int $size): Matrix
    {
        mt_srand(42 + $size);

        return new Matrix(array_map(
            fn(): array => $this->randomRow($size),
            range(1, $size),
        ));
    }

    /**
     * Builds one row of pseudo-random floats between zero and one
     *
     * @param positive-int $size Number of cells
     *
     * @return non-empty-list<float>
     */
    private function randomRow(int $size): array
    {
        return array_map(
            static fn(): float => mt_rand() / mt_getrandmax(),
            range(1, $size),
        );
    }

    /**
     * Returns the effective rate of a square multiplication, which performs 2n³ floating point operations
     *
     * @param positive-int $size         Square matrix dimension
     * @param float        $milliseconds Measured duration
     */
    private function gflops(int $size, float $milliseconds): float
    {
        if ($milliseconds <= 0.0) {
            return 0.0;
        }

        return 2.0 * $size ** 3 / ($milliseconds / 1000) / 1_000_000_000;
    }

    /**
     * Prints what the numbers below were measured on
     */
    private function printEnvironment(): void
    {
        $lines = [
            'PHP ' . PHP_VERSION . ' on ' . php_uname('s') . ' ' . php_uname('m'),
            'CPU: ' . $this->cpuModel(),
            'OpenCL device type: ' . ($this->openClDevice() ?? 'gpu (default)'),
            'Repetitions: ' . $this->repeat . ' (median reported, one warm-up discarded)',
            'Reproduce: php bench/benchmark.php ' . implode(' ', $this->arguments),
        ];

        foreach ($lines as $line) {
            echo($this->markdown ? '_' . $line . '_' . PHP_EOL . PHP_EOL : $line . PHP_EOL);
        }
    }

    /**
     * Prints the measurements as a markdown table
     *
     * @param array<string, array<string, float|null>> $measurements Milliseconds, keyed by "op:size" then backend
     * @param list<string>                             $available    Backends that could actually be measured
     */
    private function printMarkdown(array $measurements, array $available): void
    {
        $header = ['Operation', 'Size'];
        foreach ($available as $backend) {
            $header[] = '`' . $backend . '`';
        }
        if (in_array(Backends::PHP, $available, true)) {
            foreach (array_slice($available, 1) as $backend) {
                $header[] = '`' . $backend . '` speed-up';
            }
        }

        echo '| ' . implode(' | ', $header) . ' |' . PHP_EOL;
        echo '|' . str_repeat(' --- |', count($header)) . PHP_EOL;

        foreach ($this->operations as $operation) {
            foreach ($this->sizes as $size) {
                $row  = [$this->operationLabel($operation), $size . '×' . $size];
                $cell = $measurements[$operation . ':' . $size] ?? [];

                foreach ($available as $backend) {
                    $milliseconds = $cell[$backend] ?? null;
                    $row[]        = $milliseconds === null
                        ? '—'
                        : number_format($milliseconds, 2) . ' ms'
                            . ($operation === 'gemm'
                                ? sprintf(' (%s GFLOP/s)', number_format($this->gflops($size, $milliseconds), 1))
                                : '');
                }

                $reference = $cell[Backends::PHP] ?? null;
                if ($reference !== null) {
                    foreach (array_slice($available, 1) as $backend) {
                        $milliseconds = $cell[$backend] ?? null;
                        $row[]        = $milliseconds === null || $milliseconds <= 0.0
                            ? '—'
                            : '×' . number_format($reference / $milliseconds, 1);
                    }
                }

                echo '| ' . implode(' | ', array_map('strval', $row)) . ' |' . PHP_EOL;
            }
        }
    }

    /**
     * Returns the human-readable name of an operation
     *
     * @param string $operation Operation name
     */
    private function operationLabel(string $operation): string
    {
        return match ($operation) {
            'gemm'  => 'Multiplication `$a * $b`',
            'add'   => 'Addition `$a + $b`',
            'scal'  => 'Scaling `$a * 2.5`',
            default => $operation,
        };
    }

    /**
     * Reads the CPU model from the kernel, when it exposes one
     */
    private function cpuModel(): string
    {
        $information = @file_get_contents('/proc/cpuinfo');
        if (is_string($information) && preg_match('/^model name\s*:\s*(.+)$/m', $information, $matches) === 1) {
            return trim($matches[1]);
        }

        return php_uname('m') . ' (model unknown)';
    }

    /**
     * Returns the requested OpenCL device type, if the environment pins one
     */
    private function openClDevice(): ?string
    {
        $device = getenv('NATIVE_PHP_MATRIX_CL_DEVICE');

        return is_string($device) && trim($device) !== '' ? trim($device) : null;
    }

    /**
     * Splits a comma separated option into a list, falling back to a default
     *
     * @param array<string, mixed> $options  Parsed options
     * @param string               $name     Option name
     * @param list<string>         $fallback Value to use when the option is absent
     *
     * @return list<string>
     */
    private static function listOption(array $options, string $name, array $fallback): array
    {
        $value = self::scalarOption($options, $name, null);
        if ($value === null) {
            return $fallback;
        }

        $items = array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn(string $item): bool => $item !== '',
        ));

        return $items === [] ? $fallback : $items;
    }

    /**
     * Returns a single option value
     *
     * @param array<string, mixed> $options  Parsed options
     * @param string               $name     Option name
     * @param string|null          $fallback Value to use when the option is absent
     */
    private static function scalarOption(array $options, string $name, ?string $fallback): ?string
    {
        $value = $options[$name] ?? null;
        if (is_array($value)) {
            $value = $value[count($value) - 1] ?? null;
        }

        return is_string($value) ? $value : $fallback;
    }

    /**
     * Returns the usage text
     */
    private static function usage(): string
    {
        return <<<'USAGE'
            Usage: php bench/benchmark.php [options]

              --sizes=64,128,256,512   Square matrix dimensions to measure
              --backends=php,blas,clblast  Backends to measure, unavailable ones are reported and skipped
              --ops=gemm,add,scal      Operations to measure
              --repeat=5               Timed repetitions per combination, the median is reported
              --markdown               Print a table ready to paste into the README
              --help                   Show this text

            The OpenCL device type of the clblast backend is chosen with NATIVE_PHP_MATRIX_CL_DEVICE=gpu|cpu|all.

            USAGE;
    }
}

Benchmark::fromCommandLine()->run();
