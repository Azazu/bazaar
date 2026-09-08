<?php

namespace App\Models;

use App\Jobs\ProcessImage;
use App\Services\Media\ImageProcessor;
use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One gallery image. `path` is the uploaded original on the public disk; the resized
 * derivatives (see ImageProcessor::SIZES) are generated in the background and live next to it.
 */
class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    public const DISK = ImageProcessor::DISK;

    protected $fillable = ['product_id', 'path', 'position'];

    protected static function booted(): void
    {
        // A new original — on creation or when an existing row's file is replaced in the gallery
        // editor — is resized in the background (after commit, so the worker finds the row), and
        // the file it replaced goes away once the change is committed; a rolled-back edit keeps it.
        static::created(fn (self $image) => ProcessImage::dispatch($image->path));

        static::updated(function (self $image) {
            if (! $image->wasChanged('path')) {
                return; // reordered or otherwise edited: same file, nothing to resize
            }

            $previous = $image->getOriginal('path'); // still the old value here: originals sync after `saved`

            if (is_string($previous) && $previous !== '' && $previous !== $image->path) {
                DB::afterCommit(fn () => app(ImageProcessor::class)->deletePath($previous));
            }

            ProcessImage::dispatch($image->path);
        });

        // Remove the files with the row — originals and every derivative.
        static::deleted(fn (self $image) => app(ImageProcessor::class)->delete($image));
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Public URL of a derivative (original until the queue has produced it). */
    public function url(string $size = 'card'): string
    {
        return ImageProcessor::urlFor($this->path, $size);
    }
}
