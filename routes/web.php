<?php

use App\Http\Controllers\Settings;
use App\Http\Controllers\Markets\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::get('markets', [SubscriptionController::class, 'index'])->name('markets.index');
    Route::post('markets', [SubscriptionController::class, 'store'])->name('markets.store');
    Route::delete('markets/{subscription}', [SubscriptionController::class, 'destroy'])->name('markets.destroy');
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [Settings\ProfileController::class, 'edit'])->name('settings.profile.edit');
    Route::put('settings/profile', [Settings\ProfileController::class, 'update'])->name('settings.profile.update');
    Route::delete('settings/profile', [Settings\ProfileController::class, 'destroy'])->name('settings.profile.destroy');
    Route::get('settings/password', [Settings\PasswordController::class, 'edit'])->name('settings.password.edit');
    Route::put('settings/password', [Settings\PasswordController::class, 'update'])->name('settings.password.update');
    Route::get('settings/api-key', [Settings\ApiKeyController::class, 'edit'])->name('settings.api-key.edit');
    Route::put('settings/api-key', [Settings\ApiKeyController::class, 'update'])->name('settings.api-key.update');
    Route::get('settings/appearance', [Settings\AppearanceController::class, 'edit'])->name('settings.appearance.edit');
});

require __DIR__.'/auth.php';
