<?php

namespace App\Models;

use App\Jobs\ProcessImage;
use App\Services\Media\ImageProcessor;
use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        // Resizing is slow: queue it (after commit, so the worker finds the row).
        static::created(fn (self $image) => ProcessImage::dispatch($image->path));

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
