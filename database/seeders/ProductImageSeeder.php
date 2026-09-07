<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Media\ImageProcessor;
use App\Services\Media\PlaceholderImage;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Gives every product without images 1–3 generated pictures. Idempotent, so it can be
 * re-run on an existing database. Derivatives are produced inline (model events are off
 * in seeders, and a queue worker may not be running).
 */
class ProductImageSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(PlaceholderImage $placeholders, ImageProcessor $processor): void
    {
        $disk = Storage::disk(ProductImage::DISK);

        Product::doesntHave('images')->each(function (Product $product) use ($placeholders, $processor, $disk) {
            foreach (range(0, ($product->id % 3)) as $position) {
                $path = "products/{$product->id}/seed-{$position}.webp";
                $disk->put($path, $placeholders->generate($product->id * 10 + $position));

                $processor->process($product->images()->create(['path' => $path, 'position' => $position]));
            }
        });
    }
}
