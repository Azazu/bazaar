<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = Category::factory(5)->create();

        Product::factory(40)
            ->has(ProductVariant::factory()->count(3), 'variants')
            ->create()
            ->each(function (Product $product) use ($categories) {
                $product->categories()->attach($categories->random(2)->pluck('id'));
            });
    }
}
