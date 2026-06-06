<?php

namespace App\Http\Controllers;

use App\Models\CuisineCategory;
use Inertia\Inertia;

class CuisineController extends Controller
{
    public function show(CuisineCategory $category)
    {
        $category->load([
            'cuisines' => fn ($query) => $query->orderBy('sort_order'),
        ]);

        return Inertia::render('Cuisine/Subcategories', [
            'category' => $category,
        ]);
    }
}
