<?php

namespace App\Http\Controllers;

use App\Models\CuisineCategory;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function __invoke()
    {
        $categories = CuisineCategory::withCount('cuisines')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Welcome', [
            'categories' => $categories,
        ]);
    }
}
