<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;


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
    {/*
        Vite::prefetch(concurrency: 3);
        if (config('app.env') !== 'local') {
            URL::forceScheme('https');
        }

        // Если проект работает в окружении production, принудительно переводим все ссылки на HTTPS
        if (config('app.env') === 'production' || env('APP_ENV') === 'production') {
            URL::forceScheme('https');
        }
        */

        // Оставляем системную настройку Vite
        Vite::prefetch(concurrency: 3);

        // Железобетонная защита пагинации и ссылок для продакшена (HTTPS)
        if (config('app.env') === 'production' || env('APP_ENV') === 'production') {
            // 1. Переводим генератор ассетов и роутов на HTTPS
            URL::forceScheme('https');

            // 2. Принудительно заставляем пагинатор переписывать ссылки страниц на HTTPS
            \Illuminate\Pagination\Paginator::currentPathResolver(function () {
                return str_replace('http://', 'https://', request()->url());
            });
        }
    }
}
