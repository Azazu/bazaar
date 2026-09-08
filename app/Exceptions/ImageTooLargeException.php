<?php

namespace App\Exceptions;

use RuntimeException;

/** An original exceeds ImageProcessor::MAX_DIMENSION on a side: decoding it would exhaust the worker. */
class ImageTooLargeException extends RuntimeException
{
    public function __construct(string $path, int $width, int $height, int $max)
    {
        parent::__construct("Image {$path} is {$width}×{$height}; at most {$max}×{$max} is processed.");
    }
}
