<?php

namespace App\Jobs;

use App\Models\ProductImage;
use App\Services\Media\ImageProcessor;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Generates the resized derivatives for an uploaded product image. Runs after the
 * surrounding transaction commits, otherwise the worker could look up a row that
 * isn't visible yet.
 */
class ProcessProductImage implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public ProductImage $image) {}

    public function handle(ImageProcessor $processor): void
    {
        $processor->process($this->image);
    }
}
