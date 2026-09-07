<?php

namespace App\Models;

use App\Jobs\ProcessProductImage;
use App\Services\Media\ImageProcessor;
use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One gallery image. `path` is the uploaded original on the public disk; the resized
 * derivatives (see ImageProcessor::SIZES) are generated in the background and live next to it.
 */
class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    public const DISK = 'public';

    protected $fillable = ['product_id', 'path', 'position'];

    protected static function booted(): void
    {
        // Resizing is slow: queue it (after commit, so the worker finds the row).
        static::created(fn (self $image) => ProcessProductImage::dispatch($image));

        // Remove the files with the row — originals and every derivative.
        static::deleted(fn (self $image) => app(ImageProcessor::class)->delete($image));
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Public URL of a derivative, falling back to the original until the queued
     * processing has produced it — so a freshly uploaded image is never a broken <img>.
     */
    public function url(string $size = 'card'): string
    {
        $derivative = ImageProcessor::derivativePath($this->path, $size);

        $path = Storage::disk(self::DISK)->exists($derivative) ? $derivative : $this->path;

        return asset('storage/'.$path);
    }
}
