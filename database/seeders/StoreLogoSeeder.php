<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Services\Media\ImageProcessor;
use App\Services\Media\PlaceholderImage;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/** A generated logo for every store without one; idempotent, processes inline (no worker needed). */
class StoreLogoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(PlaceholderImage $placeholders, ImageProcessor $processor): void
    {
        Store::whereNull('logo')->each(function (Store $store) use ($placeholders, $processor) {
            $path = "stores/{$store->id}/logo.webp";

            Storage::disk(ImageProcessor::DISK)->put($path, $placeholders->generate(1000 + $store->id, size: 400));
            $store->update(['logo' => $path]);
            $processor->processPath($path);
        });
    }
}
