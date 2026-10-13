<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;

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
        DevCommands::artisan('serve --port=8000 --tries=0', 'server');

        DevCommands::artisan(
            'queue:listen repositories --queue=repositories --timeout=210',
            'repositories',
        );

        DevCommands::artisan(
            'queue:listen implementations --queue=implementations --tries=1 --timeout=0',
            'implementations',
        );
    }
}
