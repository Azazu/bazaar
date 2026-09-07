<?php

use App\Jobs\ProcessImage;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Media\ImageProcessor;
use App\Services\Media\PlaceholderImage;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('public'));

it('queues processing when a logo is set and cleans up the previous one', function () {
    Bus::fake();
    Storage::disk('public')->put('stores/old.webp', app(PlaceholderImage::class)->generate(1, size: 100));
    $store = Store::factory()->create(['logo' => null]);

    $store->update(['logo' => 'stores/old.webp']);
    Bus::assertDispatched(ProcessImage::class, fn (ProcessImage $job) => $job->path === 'stores/old.webp');

    $store->update(['logo' => 'stores/new.webp']);
    Storage::disk('public')->assertMissing('stores/old.webp');
    Bus::assertDispatched(ProcessImage::class, fn (ProcessImage $job) => $job->path === 'stores/new.webp');
});

it('serves the logo through the shared derivative pipeline', function () {
    Storage::disk('public')->put('stores/1/logo.webp', app(PlaceholderImage::class)->generate(2, size: 400));
    $store = Store::withoutEvents(fn () => Store::factory()->create(['logo' => 'stores/1/logo.webp']));

    expect($store->logoUrl('thumb'))->toEndWith('/storage/stores/1/logo.webp'); // original until processed

    app(ImageProcessor::class)->processPath($store->logo);

    expect($store->logoUrl('thumb'))->toEndWith('/storage/stores/1/logo-thumb.webp')
        ->and(Store::factory()->create(['logo' => null])->logoUrl())->toBeNull();
});

it('shows the seller on the product page and in the API', function () {
    $store = Store::withoutEvents(fn () => Store::factory()->create(['name' => 'Acme Goods', 'logo' => 'stores/acme.webp']));
    $product = Product::factory()->for($store)->create();

    $this->get(route('products.show', $product))->assertOk()->assertSee('Sold by')->assertSee('Acme Goods')->assertSee('stores/acme.webp');
    $this->getJson("/api/v1/products/{$product->slug}")->assertJsonPath('data.store.logo', fn (string $url) => str_ends_with($url, 'stores/acme.webp'));
});

it('shows the store identity on the vendor dashboard', function () {
    $vendor = User::factory()->create();
    $vendor->assignRole('vendor');
    Store::withoutEvents(fn () => Store::factory()->create(['owner_id' => $vendor->id, 'name' => 'Acme Goods', 'logo' => 'stores/acme.webp']));

    $this->actingAs($vendor)->get(route('vendor.orders'))->assertOk()->assertSee('Acme Goods')->assertSee('stores/acme.webp');
});
