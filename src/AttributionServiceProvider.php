<?php

namespace SwooInc\Attribution;

use Illuminate\Support\ServiceProvider;
use SwooInc\Attribution\Console\Commands\ImportAttribution;

class AttributionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(
            __DIR__.'/../resources/views',
            'attribution'
        );

        $jsPath = __DIR__.'/../resources/js/attribution.js';
        $js = file_exists($jsPath) ? file_get_contents($jsPath) : '';

        $this->app['view']->composer(
            'attribution::attribution',
            static function ($view) use ($js) {
                $view->with('attributionJs', $js);
            }
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportAttribution::class,
            ]);

            $this->publishes([
                __DIR__.'/../database/migrations/create_attribution_records_table.php'
                    => database_path(
                        'migrations/'.date('Y_m_d_His')
                        .'_create_attribution_records_table.php'
                    ),
            ], 'attribution-migrations');

            $this->publishes([
                __DIR__.'/../database/migrations/add_ttclid_to_attribution_records_table.php'
                    => database_path(
                        'migrations/'.date('Y_m_d_His')
                        .'_add_ttclid_to_attribution_records_table.php'
                    ),
            ], 'attribution-ttclid');

            $this->publishes([
                __DIR__.'/../config/attribution.php'
                    => config_path('attribution.php'),
            ], 'attribution-config');

            $this->publishes([
                __DIR__.'/../resources/js/attribution.js'
                    => public_path('vendor/attribution/attribution.js'),
            ], 'attribution-assets');
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/attribution.php',
            'attribution'
        );

        $this->app->singleton(AttributionService::class);
    }
}
