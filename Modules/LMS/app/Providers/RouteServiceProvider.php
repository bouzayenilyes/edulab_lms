<?php

namespace Modules\LMS\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * Called before routes are registered.
     *
     * Register any model bindings or pattern based filters.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Define the routes for the application.
     */
    public function map(): void
    {
        $this->mapWebRoutes();
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     */
    protected function mapWebRoutes(): void
    {
        Route::middleware('web')->group(module_path('LMS', '/routes/web.php'));
        Route::middleware('web')->group(module_path('LMS', '/routes/admin.php'));
        Route::middleware('web')->group(module_path('LMS', '/routes/instructor.php'));
        Route::middleware('web')->group(module_path('LMS', '/routes/student.php'));
        Route::middleware('web')->group(module_path('LMS', '/routes/organization.php'));
    }
}
