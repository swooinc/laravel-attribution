<?php

namespace SwooInc\Attribution;

class Attribution
{
    /**
     * Register the package's routes.
     *
     * Call this inside your application's route group to inherit
     * whatever middleware (auth, throttle, etc.) you want applied.
     *
     * Example in routes/web.php:
     *
     *   Route::middleware('auth')->group(function () {
     *       Attribution::routes();
     *   });
     */
    public static function routes(): void
    {
        require __DIR__.'/../routes/attribution.php';
    }
}
