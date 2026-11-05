<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PushSubscriptionController;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
});
Route::middleware(['auth', 'household'])->group(function () {
    Route::get('/', \App\Livewire\Schedule::class)->name('schedule');
    Route::get('/swaps', \App\Livewire\Swaps::class)->name('swaps');
    Route::get('/inbox', \App\Livewire\Inbox::class)->name('inbox');
    Route::get('/preferences', \App\Livewire\Preferences::class)->name('preferences');
    Route::get('/household', \App\Livewire\HouseholdAdmin::class)->middleware('can:admin')->name('household');
    Route::get('/password', [AuthController::class, 'password'])->name('password.edit');
    Route::put('/password', [AuthController::class, 'updatePassword'])->middleware('throttle:5,1')->name('password.update');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store'])->middleware('throttle:10,1');
    Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy'])->middleware('throttle:10,1');
});
