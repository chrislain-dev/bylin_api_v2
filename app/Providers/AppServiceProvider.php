<?php

namespace App\Providers;

use Modules\Catalogue\Models\Brand;
use Modules\Catalogue\Models\Product;
use Modules\Catalogue\Models\Category;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Modules\Catalogue\Models\ProductVariation;
use Modules\Catalogue\Observers\BrandObserver;
use Modules\Catalogue\Observers\ProductObserver;
use Modules\Catalogue\Observers\CategoryObserver;
use Modules\Catalogue\Observers\ProductVariationObserver;
use Modules\Catalogue\Console\Commands\DebugBrandMediaCommand;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (file_exists(config_path('catalogue.php'))) {
            $this->mergeConfigFrom(
                config_path('catalogue.php'),
                'catalogue'
            );
        }
    }

    public function boot(): void
    {
        // Super admin bypass for all Laravel/Spatie permission checks.
        // This prevents the dashboard from returning 403 when the role exists
        // but the permission cache or seed state is temporarily out of sync.
        Gate::before(function ($user, string $ability): ?bool {
            return method_exists($user, 'hasRole') && $user->hasRole('super_admin') ? true : null;
        });

        // ============================
        // Observers Catalogue
        // ============================
        Brand::observe(BrandObserver::class);
        Category::observe(CategoryObserver::class);
        Product::observe(ProductObserver::class);
        ProductVariation::observe(ProductVariationObserver::class);

        // ============================
        // Publication du config
        // ============================
        if ($this->app->runningInConsole()) {
            $this->commands([
                DebugBrandMediaCommand::class,
            ]);

            $this->publishes([
                config_path('catalogue.php') => config_path('catalogue.php'),
            ], 'catalogue-config');
        }
    }
}
