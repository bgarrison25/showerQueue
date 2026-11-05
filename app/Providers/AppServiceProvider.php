<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn (\App\Models\User $user) => $user->is_active && $user->is_admin);
        \Livewire\Livewire::addPersistentMiddleware([\App\Http\Middleware\EnsureHouseholdAccess::class]);
        \Illuminate\Support\Facades\RateLimiter::for('livewire', fn (\Illuminate\Http\Request $request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by($request->user()?->id ?? $request->ip()));
        \Livewire\Livewire::setUpdateRoute(fn ($handle) => \Illuminate\Support\Facades\Route::post('/livewire/update', $handle)->middleware(['web', 'auth', 'household', 'throttle:livewire']));
    }
}
