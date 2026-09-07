<?php

namespace App\Services\Media;

use App\Models\ProductImage;
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
        $disk = Storage::disk(ProductImage::DISK);

        if (! $disk->exists($image->path)) {
            return; // file already gone (deleted before the worker got to it)
        }

        $source = $this->images->decode($disk->get($image->path));

        foreach (self::SIZES as $size => [$width, $height, $mode]) {
            $resized = $mode === 'cover'
                ? (clone $source)->cover($width, $height)
                : (clone $source)->scaleDown($width, $height);

            $disk->put(self::derivativePath($image->path, $size), $this->encode($resized));
        }
    }

    public function delete(ProductImage $image): void
    {
        $disk = Storage::disk(ProductImage::DISK);

        $disk->delete([
            $image->path,
            ...array_map(fn (string $size) => self::derivativePath($image->path, $size), array_keys(self::SIZES)),
        ]);
    }

    private function encode(ImageInterface $image): string
    {
        return $image->encode(new WebpEncoder(self::QUALITY))->toString();
    }
}
