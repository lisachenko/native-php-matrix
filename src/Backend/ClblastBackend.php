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
use FFI\CData;
use FFI\Exception as FFIException;
use RuntimeException;
use Throwable;

/**
 * GPU driver backed by CLBlast on top of OpenCL, reached through FFI
 *
 * CLBlast is a BLAS written in OpenCL, which makes it the portable way to reach a GPU from PHP: the same driver
 * runs on NVIDIA, AMD and Intel hardware, on the integrated GPU of a laptop, and — through an OpenCL runtime such
 * as PoCL — on the CPU, which is how the GPU code path is exercised in continuous integration.
 *
 * Everything is declared in a single FFI::cdef against the CLBlast library: the loader resolves the `cl*` symbols
 * through the OpenCL library CLBlast itself is linked against, so no second binding is needed. The OpenCL handles
 * are opaque pointers here, as they are in C.
 *
 * Two behaviours deserve to be spelled out. The device type is chosen with the `NATIVE_PHP_MATRIX_CL_DEVICE`
 * variable (`gpu` by default, `cpu` or `all`), and this driver is never selected automatically — copying data
 * across a bus is a decision, not a default. And unlike host memory, device buffers are not garbage collected:
 * every one of them is released in a `finally` block, whatever happens to the operation.
 */
final class ClblastBackend implements BackendInterface
{
    use AcceleratedBackendTrait;

    /**
     * Name of the environment variable choosing the OpenCL device type: `gpu`, `cpu` or `all`
     */
    public const string DEVICE_ENVIRONMENT_VARIABLE = 'NATIVE_PHP_MATRIX_CL_DEVICE';

    /**
     * OpenCL device type bits
     */
    private const int CL_DEVICE_TYPE_CPU = 2;
    private const int CL_DEVICE_TYPE_GPU = 4;
    private const int CL_DEVICE_TYPE_ALL = 0xFFFFFFFF;

    /**
     * OpenCL buffer allocated for reading and writing by kernels
     */
    private const int CL_MEM_READ_WRITE = 1;

    /**
     * OpenCL status of a successful call
     */
    private const int CL_SUCCESS = 0;

    /**
     * OpenCL flag requesting a blocking transfer
     */
    private const int CL_TRUE = 1;

    /**
     * CLBlast layout: cells are stored row by row
     */
    private const int CLBLAST_ROW_MAJOR = 101;

    /**
     * CLBlast transposition: use the operand as it is stored
     */
    private const int CLBLAST_NO_TRANS = 111;

    /**
     * Maximum number of platforms and devices inspected while looking for a usable one
     */
    private const int MAX_ENTRIES = 16;

    /**
     * Shared library names to try, in order of preference
     *
     * @var list<string>
     */
    private const array SHARED_LIBRARIES = [
        'libclblast.so.1',
        'libclblast.so',
        'libclblast.dylib',
        '/opt/homebrew/opt/clblast/lib/libclblast.dylib',
        '/usr/local/opt/clblast/lib/libclblast.dylib',
    ];

    /**
     * Inline declarations of CLBlast and of the OpenCL subset needed to feed it
     *
     * The OpenCL handles are opaque pointers, exactly as the OpenCL headers define them, and the dimensions are
     * `size_t` as CLBlast expects. `clCreateCommandQueueWithProperties` is the OpenCL 2.0 entry point, with the
     * deprecated 1.x one declared next to it for runtimes that only export the older name.
     */
    private const string DECLARATIONS = <<<'CDEF'
        typedef void* cl_platform_id;
        typedef void* cl_device_id;
        typedef void* cl_context;
        typedef void* cl_command_queue;
        typedef void* cl_mem;
        typedef void* cl_event;

        int clGetPlatformIDs(unsigned int num_entries, cl_platform_id* platforms, unsigned int* num_platforms);
        int clGetDeviceIDs(cl_platform_id platform, unsigned long long device_type, unsigned int num_entries,
                           cl_device_id* devices, unsigned int* num_devices);
        cl_context clCreateContext(void* properties, unsigned int num_devices, cl_device_id* devices,
                                   void* pfn_notify, void* user_data, int* errcode_ret);
        cl_command_queue clCreateCommandQueueWithProperties(cl_context context, cl_device_id device,
                                                            void* properties, int* errcode_ret);
        cl_command_queue clCreateCommandQueue(cl_context context, cl_device_id device,
                                              unsigned long long properties, int* errcode_ret);
        cl_mem clCreateBuffer(cl_context context, unsigned long long flags, size_t size, void* host_ptr,
                              int* errcode_ret);
        int clEnqueueWriteBuffer(cl_command_queue queue, cl_mem buffer, unsigned int blocking_write, size_t offset,
                                 size_t size, const void* ptr, unsigned int num_events_in_wait_list,
                                 const cl_event* event_wait_list, cl_event* event);
        int clEnqueueReadBuffer(cl_command_queue queue, cl_mem buffer, unsigned int blocking_read, size_t offset,
                                size_t size, void* ptr, unsigned int num_events_in_wait_list,
                                const cl_event* event_wait_list, cl_event* event);
        int clFinish(cl_command_queue queue);
        int clReleaseMemObject(cl_mem memobj);
        int clReleaseCommandQueue(cl_command_queue queue);
        int clReleaseContext(cl_context context);

        int CLBlastDgemm(int layout, int a_transpose, int b_transpose, size_t m, size_t n, size_t k, double alpha,
                         const cl_mem a_buffer, size_t a_offset, size_t a_ld,
                         const cl_mem b_buffer, size_t b_offset, size_t b_ld, double beta,
                         cl_mem c_buffer, size_t c_offset, size_t c_ld,
                         cl_command_queue* queue, cl_event* event);
        int CLBlastDaxpy(size_t n, double alpha, const cl_mem x_buffer, size_t x_offset, size_t x_inc,
                         cl_mem y_buffer, size_t y_offset, size_t y_inc, cl_command_queue* queue, cl_event* event);
        int CLBlastDscal(size_t n, double alpha, cl_mem x_buffer, size_t x_offset, size_t x_inc,
                         cl_command_queue* queue, cl_event* event);
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
     * OpenCL context for the selected device, kept for the lifetime of the process
     */
    private ?CData $context = null;

    /**
     * Single-element array holding the command queue, as CLBlast takes a pointer to it
     */
    private ?CData $queue = null;

    /**
     * Loads CLBlast, initialises a device and multiplies a 1×1 matrix on it
     *
     * The probe runs a real kernel because everything up to that point can succeed on a machine that still cannot
     * compute: an OpenCL runtime with no device, a driver that fails to build kernels, a device that is busy. All
     * of it collapses into "unavailable" here, where the answer is still a return value and not a fatal error.
     */
    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            $left  = Float64Buffer::allocate(1);
            $right = Float64Buffer::allocate(1);

            Float64Buffer::write($left, 0, 3.0);
            Float64Buffer::write($right, 0, 4.0);

            $product         = $this->gemm($left, $right, 1, 1, 1);
            $this->available = Float64Buffer::read($product, 0) === 12.0;
        } catch (Throwable) {
            $this->available = false;
        }

        return $this->available;
    }

    /**
     * {@inheritDoc}
     */
    public function sum(CData $left, CData $right, int $rows, int $columns): CData
    {
        return $this->axpy($left, $right, $rows * $columns, 1.0);
    }

    /**
     * {@inheritDoc}
     */
    public function subtract(CData $left, CData $right, int $rows, int $columns): CData
    {
        return $this->axpy($left, $right, $rows * $columns, -1.0);
    }

    /**
     * {@inheritDoc}
     */
    public function multiply(CData $left, CData $right, int $rows, int $inner, int $columns): CData
    {
        return $this->gemm($left, $right, $rows, $inner, $columns);
    }

    /**
     * {@inheritDoc}
     */
    public function multiplyByScalar(CData $matrix, float $value, int $rows, int $columns): CData
    {
        return $this->scal($matrix, $rows * $columns, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function divideByScalar(CData $matrix, float $value, int $rows, int $columns): CData
    {
        // CLBlast scales just like CBLAS does, so this is a multiplication by the reciprocal — exact for powers of
        // two, within one unit in the last place otherwise. A zero divisor raises DivisionByZeroError right here,
        // exactly as the pure-PHP driver would
        return $this->scal($matrix, $rows * $columns, 1.0 / $value);
    }

    /**
     * Multiplies two matrices on the device
     *
     * The operands are uploaded straight from the buffers the matrices are stored in, and the result is read back
     * into the buffer that becomes the storage of the new matrix: the host side of this transfer costs nothing
     * beyond the transfer itself.
     *
     * @param CData        $left    Left operand cells, row-major `double[rows * inner]`
     * @param CData        $right   Right operand cells, row-major `double[inner * columns]`
     * @param positive-int $rows    Number of rows of the left operand
     * @param positive-int $inner   Shared dimension
     * @param positive-int $columns Number of columns of the right operand
     *
     * @return CData Freshly allocated buffer holding the product
     */
    private function gemm(CData $left, CData $right, int $rows, int $inner, int $columns): CData
    {
        $library = $this->library();
        $queue   = $this->queue();

        $hostA   = $left;
        $hostB   = $right;
        $hostC   = Float64Buffer::allocate($rows * $columns);
        $buffers = [];

        try {
            $bufferA = $buffers[] = $this->createBuffer($rows * $inner);
            $bufferB = $buffers[] = $this->createBuffer($inner * $columns);
            $bufferC = $buffers[] = $this->createBuffer($rows * $columns);

            $this->write($bufferA, $hostA, $rows * $inner);
            $this->write($bufferB, $hostB, $inner * $columns);
            // beta is 0.0, so the previous contents of C are not part of the result — but uninitialised device
            // memory multiplied by zero is not guaranteed to be zero on every runtime, so C is written as well
            $this->write($bufferC, $hostC, $rows * $columns);

            $this->check($library->CLBlastDgemm(
                self::CLBLAST_ROW_MAJOR,
                self::CLBLAST_NO_TRANS,
                self::CLBLAST_NO_TRANS,
                $rows,
                $columns,
                $inner,
                1.0,
                $bufferA,
                0,
                $inner,
                $bufferB,
                0,
                $columns,
                0.0,
                $bufferC,
                0,
                $columns,
                $queue,
                null,
            ), 'CLBlastDgemm');

            $this->check($library->clFinish($this->commandQueue()), 'clFinish');
            $this->read($bufferC, $hostC, $rows * $columns);

            return $hostC;
        } finally {
            $this->release($buffers);
        }
    }

    /**
     * Computes `left ± right` on the device over the flattened cells
     *
     * @param CData        $left  Left operand cells
     * @param CData        $right Right operand cells
     * @param positive-int $count Number of cells in both operands
     * @param float        $alpha Scale applied to the right operand: 1.0 or -1.0
     *
     * @return CData Freshly allocated buffer holding the result
     */
    private function axpy(CData $left, CData $right, int $count, float $alpha): CData
    {
        $library = $this->library();
        $queue   = $this->queue();

        // The result is read back into the host buffer that seeded Y, so Y must be a buffer this driver owns
        // rather than the left operand the caller still holds
        $hostX   = $right;
        $hostY   = Float64Buffer::copyOf($left, $count);
        $buffers = [];

        try {
            $bufferX = $buffers[] = $this->createBuffer($count);
            $bufferY = $buffers[] = $this->createBuffer($count);

            $this->write($bufferX, $hostX, $count);
            $this->write($bufferY, $hostY, $count);

            // daxpy accumulates into its second vector: y = alpha * x + y
            $this->check($library->CLBlastDaxpy(
                $count,
                $alpha,
                $bufferX,
                0,
                1,
                $bufferY,
                0,
                1,
                $queue,
                null,
            ), 'CLBlastDaxpy');

            $this->check($library->clFinish($this->commandQueue()), 'clFinish');
            $this->read($bufferY, $hostY, $count);

            return $hostY;
        } finally {
            $this->release($buffers);
        }
    }

    /**
     * Scales every cell of a matrix on the device
     *
     * @param CData        $matrix Operand cells
     * @param positive-int $count  Number of cells
     * @param float        $alpha  Scale factor
     *
     * @return CData Freshly allocated buffer holding the scaled cells
     */
    private function scal(CData $matrix, int $count, float $alpha): CData
    {
        $library = $this->library();
        $queue   = $this->queue();

        // Scaling reads back into the host buffer it uploaded, so it works on a copy of the operand
        $host    = Float64Buffer::copyOf($matrix, $count);
        $buffers = [];

        try {
            $buffer = $buffers[] = $this->createBuffer($count);
            $this->write($buffer, $host, $count);

            $this->check($library->CLBlastDscal($count, $alpha, $buffer, 0, 1, $queue, null), 'CLBlastDscal');
            $this->check($library->clFinish($this->commandQueue()), 'clFinish');
            $this->read($buffer, $host, $count);

            return $host;
        } finally {
            $this->release($buffers);
        }
    }

    /**
     * Allocates a device buffer able to hold the given number of doubles
     *
     * @param positive-int $count Number of doubles
     *
     * @return CData Device buffer handle
     */
    private function createBuffer(int $count): CData
    {
        $library = $this->library();
        $status  = $library->new('int');
        $buffer  = $library->clCreateBuffer(
            $this->context(),
            self::CL_MEM_READ_WRITE,
            $this->bytes($count),
            null,
            FFI::addr($status),
        );
        $this->check($this->cell($status), 'clCreateBuffer');

        return $this->handle($buffer);
    }

    /**
     * Copies a host buffer into a device buffer and waits for the copy to complete
     *
     * @param CData        $buffer Device buffer
     * @param CData        $host   Host buffer holding at least count doubles
     * @param positive-int $count  Number of doubles to copy
     */
    private function write(CData $buffer, CData $host, int $count): void
    {
        $this->check($this->library()->clEnqueueWriteBuffer(
            $this->commandQueue(),
            $buffer,
            self::CL_TRUE,
            0,
            $this->bytes($count),
            $host,
            0,
            null,
            null,
        ), 'clEnqueueWriteBuffer');
    }

    /**
     * Copies a device buffer back into a host buffer and waits for the copy to complete
     *
     * @param CData        $buffer Device buffer
     * @param CData        $host   Host buffer with room for count doubles
     * @param positive-int $count  Number of doubles to copy
     */
    private function read(CData $buffer, CData $host, int $count): void
    {
        $this->check($this->library()->clEnqueueReadBuffer(
            $this->commandQueue(),
            $buffer,
            self::CL_TRUE,
            0,
            $this->bytes($count),
            $host,
            0,
            null,
            null,
        ), 'clEnqueueReadBuffer');
    }

    /**
     * Releases device buffers
     *
     * Device memory is not reference counted by PHP, so every buffer is released explicitly, from a `finally`
     * block, whether the operation succeeded or not.
     *
     * @param list<CData> $buffers Device buffers to release
     */
    private function release(array $buffers): void
    {
        foreach ($buffers as $buffer) {
            $this->library()->clReleaseMemObject($buffer);
        }
    }

    /**
     * Returns the number of bytes occupied by the given number of doubles
     *
     * @param positive-int $count Number of doubles
     */
    private function bytes(int $count): int
    {
        return Float64Buffer::bytes($count);
    }

    /**
     * Returns the pointer to the command queue that CLBlast entry points expect
     */
    private function queue(): CData
    {
        return $this->handle($this->library()->cast('cl_command_queue*', FFI::addr($this->queueHolder())));
    }

    /**
     * Returns the command queue handle itself, as the OpenCL entry points expect
     */
    private function commandQueue(): CData
    {
        return $this->handle($this->queueHolder()[0]);
    }

    /**
     * Returns the single-element array holding the command queue, initialising the device on first use
     *
     * @throws BackendNotAvailableException When no platform, device, context or queue could be obtained
     */
    private function queueHolder(): CData
    {
        if ($this->queue !== null) {
            return $this->queue;
        }

        $library = $this->library();
        $device  = $this->findDevice();
        $status  = $library->new('int');

        // The pointer is taken before the handle is stored, so that the array is still typed when it is cast
        $devices       = $library->new('cl_device_id[1]');
        $devicePointer = $library->cast('cl_device_id*', FFI::addr($devices));
        $devices[0]    = $device;

        $context = $library->clCreateContext(
            null,
            1,
            $devicePointer,
            null,
            null,
            FFI::addr($status),
        );
        if ($this->cell($status) !== self::CL_SUCCESS) {
            throw new BackendNotAvailableException(
                sprintf('OpenCL context could not be created, status %s', get_debug_type($this->cell($status))),
            );
        }
        $this->context = $this->handle($context);

        $queue = $library->clCreateCommandQueueWithProperties($context, $device, null, FFI::addr($status));
        if ($this->cell($status) !== self::CL_SUCCESS) {
            // OpenCL 1.x runtimes only export the deprecated entry point
            $queue = $library->clCreateCommandQueue($context, $device, 0, FFI::addr($status));
        }
        $this->check($this->cell($status), 'clCreateCommandQueue');

        $holder    = $library->new('cl_command_queue[1]');
        $holder[0] = $this->handle($queue);

        return $this->queue = $this->handle($holder);
    }

    /**
     * Returns the OpenCL context, initialising the device on first use
     */
    private function context(): CData
    {
        $this->queueHolder();
        if ($this->context === null) {
            throw new BackendNotAvailableException('OpenCL context is not initialised');
        }

        return $this->context;
    }

    /**
     * Finds the first device of the requested type, across every OpenCL platform
     *
     * @throws BackendNotAvailableException When no platform reports a device of that type
     */
    private function findDevice(): CData
    {
        $library    = $this->library();
        $deviceType = $this->deviceType();

        $platforms     = $library->new('cl_platform_id[' . self::MAX_ENTRIES . ']');
        $platformCount = $library->new('unsigned int');
        $this->check($library->clGetPlatformIDs(
            self::MAX_ENTRIES,
            $library->cast('cl_platform_id*', FFI::addr($platforms)),
            FFI::addr($platformCount),
        ), 'clGetPlatformIDs');

        for ($platform = 0; $platform < $this->counter($platformCount); $platform++) {
            $devices     = $library->new('cl_device_id[' . self::MAX_ENTRIES . ']');
            $deviceCount = $library->new('unsigned int');
            $status      = $library->clGetDeviceIDs(
                $platforms[$platform],
                $deviceType,
                self::MAX_ENTRIES,
                $library->cast('cl_device_id*', FFI::addr($devices)),
                FFI::addr($deviceCount),
            );
            if ($status === self::CL_SUCCESS && $this->counter($deviceCount) > 0) {
                return $this->handle($devices[0]);
            }
        }

        throw new BackendNotAvailableException('No OpenCL device of the requested type was found');
    }

    /**
     * Returns the OpenCL device type asked for by the environment
     *
     * An unrecognised value falls back to the GPU rather than failing: this is read while probing availability,
     * which is a context that may not throw.
     */
    private function deviceType(): int
    {
        $requested = getenv(self::DEVICE_ENVIRONMENT_VARIABLE);

        return match (is_string($requested) ? strtolower(trim($requested)) : '') {
            'cpu'   => self::CL_DEVICE_TYPE_CPU,
            'all'   => self::CL_DEVICE_TYPE_ALL,
            default => self::CL_DEVICE_TYPE_GPU,
        };
    }

    /**
     * Fails when an OpenCL or CLBlast call did not report success
     *
     * The status arrives untyped because the entry points come from parsed C declarations rather than from a PHP
     * class, so anything other than the integer CL_SUCCESS — including a value of an unexpected type — is a
     * failure worth reporting.
     *
     * @param mixed  $status Status returned by the call
     * @param string $call   Name of the call, for the message
     *
     * @throws RuntimeException When the status is not CL_SUCCESS
     */
    private function check(mixed $status, string $call): void
    {
        if ($status !== self::CL_SUCCESS) {
            throw new RuntimeException(sprintf(
                '%s() failed with status %s',
                $call,
                is_int($status) ? (string) $status : get_debug_type($status),
            ));
        }
    }

    /**
     * Narrows a value produced by a parsed C declaration to an FFI handle
     *
     * Declarations parsed at runtime carry no types a static analyser can see, so every handle crossing back into
     * PHP is checked once, here, instead of being assumed.
     *
     * @param mixed $value Value returned by an FFI call or read out of a buffer
     *
     * @throws BackendNotAvailableException When the value is not an FFI handle
     */
    private function handle(mixed $value): CData
    {
        if (!$value instanceof CData) {
            throw new BackendNotAvailableException(
                sprintf('OpenCL returned %s where a handle was expected', get_debug_type($value)),
            );
        }

        return $value;
    }

    /**
     * Reads the value out of a scalar FFI cell
     *
     * The single place in this driver that touches the pseudo-property FFI exposes on scalar handles; everything
     * else works with the value it returns.
     *
     * @param CData $scalar Scalar handle written by an OpenCL call
     *
     * @return mixed Value held by the cell
     */
    private function cell(CData $scalar): mixed
    {
        return $scalar->cdata;
    }

    /**
     * Reads a counter written by an OpenCL enumeration call
     *
     * @param CData $scalar Scalar handle holding an unsigned int
     *
     * @return int<0, max> Number of entries reported, or zero when the runtime wrote something unexpected
     */
    private function counter(CData $scalar): int
    {
        $value = $this->cell($scalar);

        return is_int($value) && $value > 0 ? $value : 0;
    }

    /**
     * Returns the loaded CLBlast binding, loading it on first use
     *
     * The `cl*` symbols are resolved through the OpenCL library CLBlast is linked against, which is why a single
     * binding is enough for both APIs.
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
            'CLBlast could not be loaded, tried: ' . implode(', ', self::SHARED_LIBRARIES),
        );
    }
}
