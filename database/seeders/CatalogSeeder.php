<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = Category::factory(5)->create();

        // Vendors, each owning one active store.
        $stores = User::factory(4)
            ->create()
            ->each(fn (User $vendor) => $vendor->assignRole('vendor'))
            ->map(fn (User $vendor) => Store::factory()->create(['owner_id' => $vendor->id]));

        $products = Product::factory(40)
            ->recycle($stores) // distribute products across the seeded stores
            ->has(ProductVariant::factory()->count(3), 'variants')
            ->create()
            ->each(fn (Product $product) => $product->categories()->attach($categories->random(2)->pluck('id')));

        // A few reviewers leave approved reviews so products have ratings to show.
        $reviewers = User::factory(5)->create();

        $products->each(function (Product $product) use ($reviewers) {
            foreach ($reviewers as $reviewer) {
                if (fake()->boolean(35)) { // ~1/3 of (product, reviewer) pairs
                    Review::factory()->for($product, 'reviewable')->create(['user_id' => $reviewer->id]);
                }
            }
        });
    }
}
