<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    /** The category tree: top-level categories with their children (cached, see Category). */
    public function __invoke(): AnonymousResourceCollection
    {
        return CategoryResource::collection(Category::cachedTree());
    }
}
