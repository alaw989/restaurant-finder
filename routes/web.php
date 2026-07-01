<?php

use App\Http\Controllers\CuisineController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\GeocodeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RestaurantController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', HomeController::class);

Route::middleware(['throttle:60,1', 'log.api'])->group(function () {
    Route::get('/api/restaurants', [RestaurantController::class, 'apiIndex']);
    Route::get('/api/geocode', [GeocodeController::class, 'reverse']);
    Route::get('/api/geocode/forward', [GeocodeController::class, 'forward']);
    Route::get('/api/geocode/search', [GeocodeController::class, 'search']);
});

Route::get('/cuisine/{category:slug}', [CuisineController::class, 'show']);

Route::get('/restaurants', [RestaurantController::class, 'index']);
Route::get('/restaurants/preview/{slug}', [RestaurantController::class, 'preview'])->name('restaurants.preview');
Route::get('/restaurants/{restaurant:slug}', [RestaurantController::class, 'show']);

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Favorites
    Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites.index');
    // spec-088: throttle the write endpoints (DoS + corpus-poisoning guard).
    Route::post('/favorites/toggle', [FavoriteController::class, 'toggle'])->middleware('throttle:30,1')->name('favorites.toggle');
    Route::post('/favorites/merge', [FavoriteController::class, 'merge'])->middleware('throttle:10,1')->name('favorites.merge');
});

require __DIR__.'/auth.php';
