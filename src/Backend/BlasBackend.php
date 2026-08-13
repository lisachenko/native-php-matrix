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

use FFI;
use FFI\Exception as FFIException;
use Throwable;

/**
 * CPU driver backed by an OpenBLAS shared library, reached through FFI
 *
 * The three CBLAS entry points below cover every operator this package overloads except exponentiation:
 * `cblas_dgemm` multiplies two matrices, `cblas_daxpy` adds a scaled vector to another one — matrix addition and
 * subtraction are exactly that over the flattened cells — and `cblas_dscal` scales a vector in place. No header
 * file is involved: the declarations are inline, and the CBLAS enums travel as the plain integers they are.
 *
 * The library is loaded lazily, and only ever the LP64 build. An ILP64 one — `libopenblas64*`, where the integer
 * arguments are 64 bit wide — must never be probed here: its ABI does not match these declarations and the
 * dimensions would arrive at the kernel as garbage.
 */
final class BlasBackend implements BackendInterface
{
    use AcceleratedBackendTrait;

    /**
     * CBLAS layout: cells are stored row by row
     */
    private const int CBLAS_ROW_MAJOR = 101;

    /**
     * CBLAS transposition: use the operand as it is stored
     */
    private const int CBLAS_NO_TRANS = 111;

    /**
     * Shared library names to try, in order of preference
     *
     * Only LP64 builds are listed, see the class docblock. The Homebrew locations are spelled out because macOS
     * does not search /opt/homebrew from the default loader path.
     *
     * @var list<string>
     */
    private const array SHARED_LIBRARIES = [
        'libopenblas.so.0',
        'libopenblas.so',
        'libopenblas.dylib',
        '/opt/homebrew/opt/openblas/lib/libopenblas.dylib',
        '/usr/local/opt/openblas/lib/libopenblas.dylib',
    ];

    /**
     * Inline declarations of the CBLAS subset this driver calls
     *
     * The `int` parameters are the CBLAS enums and the LP64 `blasint` dimensions.
     */
    private const string DECLARATIONS = <<<'CDEF'
        void cblas_dgemm(int layout, int transa, int transb, int m, int n, int k, double alpha,
                         const double* a, int lda, const double* b, int ldb, double beta, double* c, int ldc);
        void cblas_daxpy(int n, double alpha, const double* x, int incx, double* y, int incy);
        void cblas_dscal(int n, double alpha, double* x, int incx);
        CDEF;

    /**
     * Loaded library, or null while it has not been loaded yet
     */
    private ?FFI $library = null;

    /**
     * Cached answer of the availability probe: null while the question has not been asked
     */
    private ?bool $available = null;

    /**
     * Loads OpenBLAS and multiplies a 1×1 matrix with it
     *
     * The probe is a real call on purpose. A library that loads but exports no `cblas_dgemm`, or one built for a
     * different ABI, would otherwise be discovered inside an operator hook, where the failure is fatal.
     */
    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            $library = $this->library();
            $left    = $this->packRowMajor([[3.0]], 1, 1);
            $right   = $this->packRowMajor([[4.0]], 1, 1);
            $product = $this->allocate(1);
            $library->cblas_dgemm(
                self::CBLAS_ROW_MAJOR,
                self::CBLAS_NO_TRANS,
                self::CBLAS_NO_TRANS,
                1,
                1,
                1,
                1.0,
                $left,
                1,
                $right,
                1,
                0.0,
                $product,
                1,
            );

            $this->available = $product[0] === 12.0;
        } catch (Throwable) {
            $this->available = false;
        }

        return $this->available;
    }

    /**
     * {@inheritDoc}
     */
    public function sum(array $left, array $right, int $rows, int $columns): array
    {
        return $this->axpy($left, $right, $rows, $columns, 1.0);
    }

    /**
     * {@inheritDoc}
     */
    public function subtract(array $left, array $right, int $rows, int $columns): array
    {
        return $this->axpy($left, $right, $rows, $columns, -1.0);
    }

    /**
     * {@inheritDoc}
     */
    public function multiply(array $left, array $right, int $rows, int $inner, int $columns): array
    {
        $a       = $this->packRowMajor($left, $rows, $inner);
        $b       = $this->packRowMajor($right, $inner, $columns);
        $product = $this->allocate($rows * $columns);

        // beta = 0.0 is specified to ignore the previous contents of C instead of scaling them, so the product
        // buffer needs no initialisation — and FFI::new() hands out zero-filled memory anyway
        $this->library()->cblas_dgemm(
            self::CBLAS_ROW_MAJOR,
            self::CBLAS_NO_TRANS,
            self::CBLAS_NO_TRANS,
            $rows,
            $columns,
            $inner,
            1.0,
            $a,
            $inner,
            $b,
            $columns,
            0.0,
            $product,
            $columns,
        );

        return $this->unpackRows($product, $rows, $columns);
    }

    /**
     * {@inheritDoc}
     */
    public function multiplyByScalar(array $matrix, int|float $value, int $rows, int $columns): array
    {
        return $this->scal($matrix, $rows, $columns, (float) $value);
    }

    /**
     * {@inheritDoc}
     */
    public function divideByScalar(array $matrix, int|float $value, int $rows, int $columns): array
    {
        // BLAS scales, it does not divide. Multiplying by the reciprocal is exact for powers of two and within one
        // unit in the last place otherwise — the price of this driver, alongside the cast of every cell to double.
        // Dividing by zero raises the very same DivisionByZeroError the pure-PHP driver raises
        return $this->scal($matrix, $rows, $columns, 1.0 / $value);
    }

    /**
     * Computes `left ± right` by flattening both operands into vectors
     *
     * @param non-empty-list<non-empty-list<int|float>> $left    Left operand cells
     * @param non-empty-list<non-empty-list<int|float>> $right   Right operand cells
     * @param positive-int                              $rows    Number of rows in both operands
     * @param positive-int                              $columns Number of columns in both operands
     * @param float                                     $alpha   Scale applied to the right operand: 1.0 or -1.0
     *
     * @return non-empty-list<non-empty-list<float>> Result cells
     */
    private function axpy(array $left, array $right, int $rows, int $columns, float $alpha): array
    {
        $count  = $rows * $columns;
        $vector = $this->packRowMajor($right, $rows, $columns);
        $result = $this->packRowMajor($left, $rows, $columns);

        // daxpy accumulates into its second vector: result = alpha * right + left
        $this->library()->cblas_daxpy($count, $alpha, $vector, 1, $result, 1);

        return $this->unpackRows($result, $rows, $columns);
    }

    /**
     * Scales every cell of a matrix by a factor
     *
     * @param non-empty-list<non-empty-list<int|float>> $matrix  Operand cells
     * @param positive-int                              $rows    Number of rows
     * @param positive-int                              $columns Number of columns
     * @param float                                     $alpha   Scale factor
     *
     * @return non-empty-list<non-empty-list<float>> Result cells
     */
    private function scal(array $matrix, int $rows, int $columns, float $alpha): array
    {
        $result = $this->packRowMajor($matrix, $rows, $columns);
        $this->library()->cblas_dscal($rows * $columns, $alpha, $result, 1);

        return $this->unpackRows($result, $rows, $columns);
    }

    /**
     * Returns the loaded OpenBLAS binding, loading it on first use
     *
     * @throws BackendNotAvailableException When none of the candidate library names could be loaded
     */
    private function library(): FFI
    {
        if ($this->library !== null) {
            return $this->library;
        }

        foreach (self::SHARED_LIBRARIES as $sharedLibrary) {
            try {
                return $this->library = FFI::cdef(self::DECLARATIONS, $sharedLibrary);
            } catch (FFIException) {
                // This name is simply not installed here, try the next one
                continue;
            }
        }

        throw new BackendNotAvailableException(
            'OpenBLAS could not be loaded, tried: ' . implode(', ', self::SHARED_LIBRARIES),
        );
    }
}
