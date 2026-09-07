<?php

namespace App\Services\Media;

use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Geometry\Factories\CircleFactory;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\ImageManager;

/**
 * Deterministic abstract artwork for demo products (seeders, screenshots): no network,
 * no licensing, and the same product always gets the same picture.
 */
class PlaceholderImage
{
    private const PALETTE = [
        ['#1e3a8a', '#60a5fa'], ['#7c2d12', '#fb923c'], ['#14532d', '#4ade80'],
        ['#581c87', '#c084fc'], ['#134e4a', '#2dd4bf'], ['#881337', '#fb7185'],
        ['#3f3f46', '#d4d4d8'], ['#78350f', '#fcd34d'],
    ];

    public function __construct(private readonly ImageManager $images) {}

    /** @return string WebP binary, 900×900 */
    public function generate(int $seed, int $size = 900): string
    {
        mt_srand($seed);
        [$base, $accent] = self::PALETTE[$seed % count(self::PALETTE)];

        $image = $this->images->createImage($size, $size)->fill($base);

        // A few translucent discs of the accent colour, plus one band — cheap depth.
        foreach (range(1, 4) as $i) {
            $image->drawCircle(fn (CircleFactory $circle) => $circle
                ->at(mt_rand(0, $size), mt_rand(0, $size))
                ->radius(mt_rand((int) ($size * 0.15), (int) ($size * 0.45)))
                ->background($this->withAlpha($accent, 0.18 + $i * 0.08)));
        }

        $image->drawRectangle(fn (RectangleFactory $rect) => $rect
            ->size($size, (int) ($size * 0.12))
            ->background($this->withAlpha('#ffffff', 0.10)));

        return $image->encode(new WebpEncoder(80))->toString();
    }

    private function withAlpha(string $hex, float $alpha): string
    {
        $rgb = array_map(fn (string $pair) => hexdec($pair), str_split(ltrim($hex, '#'), 2));

        return sprintf('rgba(%d, %d, %d, %.2f)', $rgb[0], $rgb[1], $rgb[2], $alpha);
    }
}
