<?php

namespace App\Jobs;

use App\Exceptions\ImageTooLargeException;
use App\Services\Media\ImageProcessor;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Generates the resized derivatives for an uploaded image (product gallery, store logo…).
 * Runs after the surrounding transaction commits, so the row that references the file
 * is already visible to whoever picks the job up.
 */
class ProcessImage implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @param  string  $path  original on the public disk */
    public function __construct(public string $path) {}

    public function handle(ImageProcessor $processor): void
    {
        try {
            $processor->processPath($this->path);
        } catch (ImageTooLargeException $e) {
            $this->fail($e); // retrying won't shrink the file; record it and move on (the original still serves)
        }
    }
}
