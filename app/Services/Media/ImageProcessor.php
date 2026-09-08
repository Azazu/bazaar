<?php

namespace App\Services\Media;

use App\Exceptions\ImageTooLargeException;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Turns an uploaded original into the fixed set of sizes the storefront uses, encoded as
 * WebP. Derivatives sit next to the original as `{name}-{size}.webp`, so their location is
 * a pure function of the original's path — no extra columns, nothing to keep in sync.
 */
class ImageProcessor
{
    /** size name => [width, height, mode] */
    public const SIZES = [
        'large' => [1200, 1200, 'contain'], // product page, fits inside the box
        'card' => [600, 600, 'cover'],      // catalog card, square crop
        'thumb' => [160, 160, 'cover'],     // gallery thumbnails, admin table
    ];

    public const DISK = 'public';

    /**
     * Longest side an original may have. Decoding is the expensive step — GD holds the whole
     * bitmap in memory (4 bytes a pixel), and a 4000×4000 original is already ~64 MB against
     * the worker's 128 MB — so the limit is enforced twice: by the upload validation rule and
     * again here, for files that arrive by any other route.
     */
    public const MAX_DIMENSION = 4000;

    /** Laravel validation rule for the upload fields, kept next to the limit it expresses. */
    public const DIMENSIONS_RULE = 'dimensions:max_width='.self::MAX_DIMENSION.',max_height='.self::MAX_DIMENSION;

    private const QUALITY = 82;

    public function __construct(private readonly ImageManager $images) {}

    public static function derivativePath(string $original, string $size): string
    {
        $info = pathinfo($original);
        $dir = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'].'/';

        return $dir.$info['filename'].'-'.$size.'.webp';
    }

    public function process(ProductImage $image): void
    {
        $this->processPath($image->path);
    }

    public function delete(ProductImage $image): void
    {
        $this->deletePath($image->path);
    }

    /**
     * Generate every derivative of an original on the public disk.
     *
     * Safe to run late or twice: an original that is gone (replaced or deleted before the
     * worker got to it) produces nothing, and if it disappears *while* we work, the
     * derivatives just written are removed again so no orphan files are left behind.
     * Oversized originals are refused before decoding (see MAX_DIMENSION).
     */
    public function processPath(string $path): void
    {
        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($path)) {
            return; // file already gone (deleted before the worker got to it)
        }

        $contents = $disk->get($path) ?? '';
        $this->assertWithinLimits($path, $contents);

        $source = $this->images->decode($contents);

        foreach (self::SIZES as $size => [$width, $height, $mode]) {
            $resized = $mode === 'cover'
                ? (clone $source)->cover($width, $height)
                : (clone $source)->scaleDown($width, $height);

            $disk->put(self::derivativePath($path, $size), $this->encode($resized));
        }

        if ($disk->missing($path)) {
            $this->deletePath($path); // replaced/deleted while we were resizing: don't leave derivatives of a ghost
        }
    }

    /**
     * Read the dimensions from the header only — no full decode — and refuse what would blow
     * the worker's memory. Logged rather than retried: the file will not get smaller.
     *
     * @throws ImageTooLargeException
     */
    private function assertWithinLimits(string $path, string $contents): void
    {
        $info = @getimagesizefromstring($contents);

        if ($info === false) {
            return; // not something getimagesize understands; let the decoder raise its own error
        }

        [$width, $height] = $info;

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            Log::warning('Image skipped: dimensions exceed the processing limit.', ['path' => $path, 'width' => $width, 'height' => $height]);

            throw new ImageTooLargeException($path, $width, $height, self::MAX_DIMENSION);
        }
    }

    /** Remove an original and all of its derivatives. */
    public function deletePath(string $path): void
    {
        Storage::disk(self::DISK)->delete([
            $path,
            ...array_map(fn (string $size) => self::derivativePath($path, $size), array_keys(self::SIZES)),
        ]);
    }

    /**
     * Public URL of a derivative, falling back to the original until the queued processing
     * has produced it — a freshly uploaded image is never a broken <img>.
     */
    public static function urlFor(string $path, string $size): string
    {
        $derivative = self::derivativePath($path, $size);

        return asset('storage/'.(Storage::disk(self::DISK)->exists($derivative) ? $derivative : $path));
    }

    private function encode(ImageInterface $image): string
    {
        return $image->encode(new WebpEncoder(self::QUALITY))->toString();
    }
}
