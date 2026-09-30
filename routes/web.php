<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Markets\DashboardSuggestionController;
use App\Http\Controllers\Markets\HumanTrainingController;
use App\Http\Controllers\Markets\IntelligenceController;
use App\Http\Controllers\Markets\SubscriptionController;
use App\Http\Controllers\Markets\SuggestionController;
use App\Http\Controllers\Markets\SuggestionReviewController;
use App\Http\Controllers\Owner\ArchiveController;
use App\Http\Controllers\Owner\ReportController;
use App\Http\Controllers\Owner\UserController;
use App\Http\Controllers\Settings;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard/markets/{subscription}/chart', [DashboardController::class, 'chart'])
        ->middleware('throttle:30,1')->name('dashboard.chart');
    Route::get('dashboard/suggestions', [DashboardSuggestionController::class, 'index'])
        ->middleware('throttle:12,1')->name('dashboard.suggestions');
    Route::post('dashboard/suggestions/dismiss', [DashboardSuggestionController::class, 'store'])
        ->middleware('throttle:12,1')->name('dashboard.suggestions.dismiss');
    Route::delete('dashboard/suggestions/dismiss', [DashboardSuggestionController::class, 'destroy'])
        ->name('dashboard.suggestions.restore');
});

Route::middleware(['auth'])->group(function () {
    Route::get('markets', [SubscriptionController::class, 'index'])->name('markets.index');
    Route::get('markets/suggestions', [SuggestionController::class, 'index'])->middleware('throttle:12,1')->name('markets.suggestions');
    Route::get('markets/suggestions/review', [SuggestionReviewController::class, 'show'])->middleware('throttle:12,1')->name('markets.suggestions.review');
    Route::put('markets/preferences', [SuggestionController::class, 'store'])->middleware('throttle:12,1')->name('markets.preferences.store');
    Route::delete('markets/preferences', [SuggestionController::class, 'destroy'])->name('markets.preferences.destroy');
    Route::get('markets/options/{exchange}', [SubscriptionController::class, 'options'])
        ->middleware('throttle:30,1')->name('markets.options');
    Route::get('markets/{subscription}/intelligence', [IntelligenceController::class, 'show'])
        ->middleware('throttle:30,1')->name('markets.intelligence');
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

Route::prefix('human-training')->name('human-training.')->middleware(['auth', 'verified', 'can:train-intelligence', 'throttle:30,1'])->group(function () {
    Route::get('/', [HumanTrainingController::class, 'index'])->name('index');
    Route::post('/', [HumanTrainingController::class, 'store'])->middleware('throttle:6,1')->name('store');
    Route::post('export', [HumanTrainingController::class, 'export'])->middleware('can:manage-server')->name('export');
    Route::get('{review}', [HumanTrainingController::class, 'show'])->whereUuid('review')->name('show');
    Route::put('{review}', [HumanTrainingController::class, 'update'])->whereUuid('review')->name('update');
});

Route::prefix('owner')->name('owner.')->middleware(['auth', 'verified', 'server-owner', 'throttle:60,1'])->group(function () {
    Route::get('/', [ReportController::class, 'index'])->name('overview');
    Route::get('users', [UserController::class, 'index'])->name('users');
    Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('throttle:12,1')->name('users.update');
    Route::get('subscriptions', [ReportController::class, 'subscriptions'])->name('subscriptions');
    Route::get('markets/{market}', [ReportController::class, 'market'])->name('markets.show');
    Route::get('intelligence', [ReportController::class, 'intelligence'])->name('intelligence');
    Route::get('intelligence/{model}', [ReportController::class, 'model'])->name('intelligence.show');
    Route::get('access', [ReportController::class, 'access'])->name('access');
    Route::get('archives', [ArchiveController::class, 'index'])->name('archives');
    Route::post('archives/archive', [ArchiveController::class, 'archive'])->middleware('throttle:6,1')->name('archives.archive');
    Route::post('archives/verify', [ArchiveController::class, 'verify'])->middleware('throttle:6,1')->name('archives.verify');
    Route::post('archives/rebuild', [ArchiveController::class, 'rebuild'])->middleware('throttle:2,1')->name('archives.rebuild');
    Route::post('archives/restore', [ArchiveController::class, 'restore'])->middleware('throttle:6,1')->name('archives.restore');
    Route::post('archives/export', [ArchiveController::class, 'export'])->middleware('throttle:2,1')->name('archives.export');
    Route::post('archives/import', [ArchiveController::class, 'import'])->middleware('throttle:2,1')->name('archives.import');
});
