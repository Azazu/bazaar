<?php

use App\Exceptions\ImageTooLargeException;
use App\Jobs\ProcessImage;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Media\ImageProcessor;
use App\Services\Media\PlaceholderImage;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\DecoderInterface;
use Intervention\Image\Interfaces\ImageInterface;

beforeEach(fn () => Storage::fake('public'));

/** Write a generated original to the fake disk and return the image row (without firing the job). */
function storedImage(Product $product, string $path = 'products/1/a.webp', int $position = 0): ProductImage
{
    Storage::disk('public')->put($path, app(PlaceholderImage::class)->generate(seed: 7));

    return ProductImage::withoutEvents(fn () => $product->images()->create(['path' => $path, 'position' => $position]));
}

it('produces every derivative size as WebP next to the original', function () {
    $image = storedImage(Product::factory()->create());

    app(ImageProcessor::class)->process($image);

    foreach (ImageProcessor::SIZES as $size => [$width, $height, $mode]) {
        $path = ImageProcessor::derivativePath($image->path, $size);
        Storage::disk('public')->assertExists($path);

        $decoded = app(ImageManager::class)->decode(Storage::disk('public')->get($path));
        $mode === 'cover'
            ? expect([$decoded->width(), $decoded->height()])->toBe([$width, $height])
            : expect($decoded->width())->toBeLessThanOrEqual($width);
    }

    expect(ImageProcessor::derivativePath('products/1/a.webp', 'card'))->toBe('products/1/a-card.webp');
});

it('falls back to the original URL until a derivative exists', function () {
    $image = storedImage(Product::factory()->create());

    expect($image->url('card'))->toEndWith('/storage/products/1/a.webp');

    app(ImageProcessor::class)->process($image);

    expect($image->url('card'))->toEndWith('/storage/products/1/a-card.webp')
        ->and($image->url('thumb'))->toEndWith('/storage/products/1/a-thumb.webp');
});

it('queues the processing job when an image is added', function () {
    Bus::fake();

    $image = Product::factory()->create()->images()->create(['path' => 'products/x.jpg']);

    Bus::assertDispatched(ProcessImage::class, fn (ProcessImage $job) => $job->path === $image->path);
});

it('removes the original and all derivatives when the image is deleted', function () {
    $image = storedImage(Product::factory()->create());
    app(ImageProcessor::class)->process($image);

    $image->delete();

    Storage::disk('public')->assertMissing('products/1/a.webp');
    Storage::disk('public')->assertMissing('products/1/a-card.webp');
    Storage::disk('public')->assertMissing('products/1/a-large.webp');
});

it('exposes the primary image in lists and the full gallery on the product', function () {
    $product = Product::factory()->create();
    storedImage($product, 'products/1/second.webp', position: 1);
    storedImage($product, 'products/1/first.webp', position: 0);

    expect($product->fresh()->primaryImage->path)->toBe('products/1/first.webp');

    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonPath('data.0.image', fn (string $url) => str_ends_with($url, '/storage/products/1/first.webp'));

    $this->getJson("/api/v1/products/{$product->slug}")
        ->assertOk()
        ->assertJsonCount(2, 'data.images')
        ->assertJsonPath('data.images.0.position', 0);
});

it('renders images on the catalog and the product page', function () {
    $product = Product::factory()->create();
    storedImage($product);

    $this->get(route('catalog.index'))->assertOk()->assertSee('storage/products/1/a.webp');
    $this->get(route('products.show', $product))->assertOk()->assertSee('storage/products/1/a.webp');
});

it('generates the same placeholder for the same seed', function () {
    $generator = app(PlaceholderImage::class);

    expect($generator->generate(42))->toBe($generator->generate(42))
        ->and($generator->generate(42))->not->toBe($generator->generate(43));

    $decoded = app(ImageManager::class)->decode($generator->generate(42, size: 300));
    expect([$decoded->width(), $decoded->height()])->toBe([300, 300]);
});

it('renders the gallery editor on the admin product form', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $product = Product::factory()->create();
    storedImage($product);

    $this->actingAs($admin)
        ->get("/admin/products/{$product->id}/edit")
        ->assertOk()
        ->assertSee('Add image')
        ->assertSee('a.webp'); // FilePond gets the existing file via JSON state (slashes escaped)
});

it('removes image files when the product itself is deleted', function () {
    $product = Product::factory()->create();
    $image = storedImage($product);
    app(ImageProcessor::class)->process($image);

    $product->delete();

    Storage::disk('public')->assertMissing('products/1/a.webp');
    Storage::disk('public')->assertMissing('products/1/a-card.webp');
    expect(ProductImage::count())->toBe(0);
});

/*
 * ME-005: replacing an image's file is a new original — it gets derivatives, the old files go;
 * a job for a file that is gone leaves nothing behind; oversized originals are refused.
 */

it('reprocesses a replaced original and removes the previous files after commit', function () {
    Bus::fake();
    $image = storedImage(Product::factory()->create(), 'products/1/old.webp');
    app(ImageProcessor::class)->process($image); // old original + its derivatives exist
    Storage::disk('public')->put('products/1/new.webp', app(PlaceholderImage::class)->generate(seed: 8));

    $image->update(['path' => 'products/1/new.webp']); // what the gallery editor does when a row's file is swapped

    Bus::assertDispatched(ProcessImage::class, fn (ProcessImage $job) => $job->path === 'products/1/new.webp');
    Storage::disk('public')->assertMissing('products/1/old.webp');
    foreach (array_keys(ImageProcessor::SIZES) as $size) {
        Storage::disk('public')->assertMissing(ImageProcessor::derivativePath('products/1/old.webp', $size));
    }
    Storage::disk('public')->assertExists('products/1/new.webp');

    // A save that changes something else (position) is not a new original.
    Bus::fake();
    $image->update(['position' => 3]);
    Bus::assertNotDispatched(ProcessImage::class);
});

it('keeps the previous original when the replacing update is rolled back', function () {
    Bus::fake();
    $image = storedImage(Product::factory()->create(), 'products/1/old.webp');
    Storage::disk('public')->put('products/1/new.webp', 'x');

    try {
        DB::transaction(function () use ($image) {
            $image->update(['path' => 'products/1/new.webp']);
            throw new RuntimeException('something else in the same transaction failed');
        });
    } catch (RuntimeException) {
    }

    Storage::disk('public')->assertExists('products/1/old.webp'); // deletion was deferred to commit, which never came
});

it('does nothing for a queued job whose file is already gone, and cleans up if the file vanishes mid-way', function () {
    // Deleted (or replaced) before the worker got to it: no derivatives, no error.
    (new ProcessImage('products/1/gone.webp'))->handle(app(ImageProcessor::class));
    expect(Storage::disk('public')->allFiles())->toBe([]);

    // Vanishes while resizing: the derivatives just produced are removed again.
    $path = 'products/1/racy.webp';
    Storage::disk('public')->put($path, app(PlaceholderImage::class)->generate(seed: 9));
    Storage::disk('public')->put(ImageProcessor::derivativePath($path, 'thumb'), 'stale'); // a leftover from an earlier run
    app()->bind(ImageManager::class, fn () => new class($path) extends ImageManager
    {
        public function __construct(private string $path)
        {
            parent::__construct(Driver::class);
        }

        /** The moment the source is decoded, the original is deleted underneath us. */
        public function decode(mixed $source, DecoderInterface|array|string|null $decoders = null): ImageInterface
        {
            Storage::disk('public')->delete($this->path);

            return parent::decode($source, $decoders);
        }
    });

    (new ProcessImage($path))->handle(app(ImageProcessor::class));

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('refuses an original larger than the processing limit, without retrying', function () {
    $wide = imagecreatetruecolor(ImageProcessor::MAX_DIMENSION + 1, 8); // cheap: one thin strip, over the limit on one side
    ob_start();
    imagepng($wide);
    Storage::disk('public')->put('products/1/huge.png', (string) ob_get_clean());

    expect(fn () => app(ImageProcessor::class)->processPath('products/1/huge.png'))->toThrow(ImageTooLargeException::class);
    foreach (array_keys(ImageProcessor::SIZES) as $size) {
        Storage::disk('public')->assertMissing(ImageProcessor::derivativePath('products/1/huge.png', $size));
    }

    // The job marks itself failed instead of throwing into the retry loop; the original still serves.
    Queue::fake();
    $job = new ProcessImage('products/1/huge.png');
    $job->handle(app(ImageProcessor::class));
    Storage::disk('public')->assertExists('products/1/huge.png');

    expect(ImageProcessor::DIMENSIONS_RULE)->toBe('dimensions:max_width=4000,max_height=4000');
});
