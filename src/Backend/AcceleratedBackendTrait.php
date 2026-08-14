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

use FFI\CData;

/**
 * The little that the drivers handing their arithmetic to a numeric library still share
 *
 * This trait used to carry the packing and unpacking between lists of rows and contiguous doubles. Both are gone:
 * matrices are stored as contiguous doubles now, so an operand is passed to a kernel exactly as it lies in memory
 * and the result buffer becomes the storage of the new matrix without being read cell by cell first.
 *
 * What remains is the one operation no BLAS implementation provides.
 */
trait AcceleratedBackendTrait
{
    /**
     * {@inheritDoc}
     */
    public function powByScalar(CData $matrix, float $value, int $rows, int $columns): CData
    {
        // Exponentiation is not part of BLAS, and CLBlast has no kernel for it either: it stays an ordinary loop
        // over the cells, so that an accelerated driver still answers every operator this package overloads
        $count  = $rows * $columns;
        $result = Float64Buffer::allocate($count);
        for ($cell = 0; $cell < $count; $cell++) {
            $result[$cell] = $matrix[$cell] ** $value;
        }

        return $result;
    }
}
