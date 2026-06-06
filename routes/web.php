<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\CuisineController;
use App\Http\Controllers\RestaurantController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', HomeController::class);

Route::get('/cuisine/{category:slug}', [CuisineController::class, 'show']);

Route::get('/restaurants', [RestaurantController::class, 'index']);
Route::get('/restaurants/{restaurant:slug}', [RestaurantController::class, 'show']);

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
