Native matrix library
-----------------

For a long time, PHP developers dreamed of being able to overload operators. And now, finally, this moment has come. 
Thanks to the PHP7.4 and the [lisachenko/z-engine](https://github.com/lisachenko/z-engine) package, we can overload the
operators of comparison, addition, multiplication, casting and much more!

This library is first ever userland PHP extension, that implements operator overloading for the `Matrix` class.

[![GitHub release](https://img.shields.io/github/release/lisachenko/native-types.svg)](https://github.com/lisachenko/native-types/releases/latest)
[![Minimum PHP Version](http://img.shields.io/badge/php-%3E%3D%207.4-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/packagist/l/lisachenko/native-types.svg)](https://packagist.org/packages/lisachenko/native-types)


Pre-requisites and initialization
--------------

As this library depends on `FFI`, it requires PHP>=7.4 and `FFI` extension to be enabled. Also, current limitations of
[lisachenko/z-engine](https://github.com/lisachenko/z-engine) are also applied (x64, NTS)

To install this library, simply add it via `composer`:
```bash
composer require lisachenko/native-types
```

Now you can test it with following example:
```php
<?php
declare(strict_types=1);

use Native\Type\Matrix;

$first  = new Matrix([[10, 20, 30]]);
$second = new Matrix([[2, 4, 6]]);
$value  = $first * 2 + $second; // Matrix([[22, 44, 66]])
```

Supported features:
 - [x] Matrices addition (`$matrixA + $matrixB`)
 - [x] Matrices subtraction (`$matrixA - $matrixB`)
 - [x] Matrices multiplication (`$matrixA * $matrixB`)
 - [x] Matrices division (`$matrixA / $matrixB`)
 - [x] Matrices division (`$matrixA / $matrixB`)
 - [x] Matrices equality check (`$matrixA == $matrixB`)
 
For the future versions, I would like to implement native SSE/AVX assembly methods to improve the performance of calculation.

 
