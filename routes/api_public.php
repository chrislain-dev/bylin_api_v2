<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API Routes
|--------------------------------------------------------------------------
| Routes accessible without authentication
*/

Route::prefix('v1')->group(function () {

    // QR Code Verification (anti-counterfeit)
    Route::post('/verify-qr/{qrCode}', [\Modules\Catalogue\Http\Controllers\AuthenticityController::class, 'verify'])
        ->middleware('throttle:30,1')
        ->name('api.verify-qr');

    // Global Content
    Route::get('/content/home', [\Modules\Core\Http\Controllers\HomeContentController::class, 'index'])
        ->name('content.home');

    // Authentication - Stricter rate limiting
    Route::prefix('auth')->name('api.auth.')->group(function () {
        // Admin auth (stateless tokens)
        Route::post('/admin/login', [\Modules\User\Http\Controllers\AuthController::class, 'login'])
            ->middleware('throttle:5,1')
            ->name('admin.login');

        Route::post('/admin/forgot-password', [\Modules\User\Http\Controllers\AuthController::class, 'forgotPassword'])
            ->middleware('throttle:3,1')
            ->name('admin.forgot-password');
        Route::post('/admin/reset-password', [\Modules\User\Http\Controllers\AuthController::class, 'resetPassword'])
            ->middleware('throttle:3,1')
            ->name('admin.reset-password');

        // Customer auth (stateful - HTTP-only cookies with sessions)
        Route::middleware('web')->group(function () {
            Route::post('/customer/register', [\Modules\Customer\Http\Controllers\CustomerAuthController::class, 'register'])
                ->middleware('throttle:5,1')
                ->name('customer.register');
            Route::post('/customer/login', [\Modules\Customer\Http\Controllers\CustomerAuthController::class, 'login'])
                ->middleware('throttle:10,1')
                ->name('customer.login');

            // Google OAuth
            Route::get('/customer/google/redirect', [\Modules\Customer\Http\Controllers\CustomerAuthController::class, 'googleRedirect'])
                ->middleware('throttle:10,1')
                ->name('customer.google.redirect');
            Route::get('/customer/google/callback', [\Modules\Customer\Http\Controllers\CustomerAuthController::class, 'googleCallback'])
                ->middleware('throttle:10,1')
                ->name('customer.google.callback');
            
            // Google ID Token verification (for frontend Google Sign-In button)
            Route::post('/customer/google/id-token', [\Modules\Customer\Http\Controllers\CustomerAuthController::class, 'googleIdToken'])
                ->middleware('throttle:10,1')
                ->name('customer.google.id-token');
        });
    });


    // Public Catalog
    Route::prefix('catalog')->name('api.catalog.')->middleware('throttle:120,1')->group(function () {
        // Products
        Route::get('/products', [\Modules\Catalogue\Http\Controllers\ProductController::class, 'index'])
            ->name('products.index');
        Route::get('/products/{id}', [\Modules\Catalogue\Http\Controllers\ProductController::class, 'show'])
            ->name('products.show');
        Route::get('/products/{id}/preorder-info', [\Modules\Catalogue\Http\Controllers\ProductController::class, 'preorderInfo'])
            ->name('products.preorder-info');

        // Categories
        Route::prefix('categories')->name('categories.')->group(function () {

            Route::get('/tree', [\Modules\Catalogue\Http\Controllers\CategoryController::class, 'tree'])
                ->name('tree');

            Route::get('/featured', [\Modules\Catalogue\Http\Controllers\CategoryController::class, 'featured'])
                ->name('featured');

            Route::get('/search', [\Modules\Catalogue\Http\Controllers\CategoryController::class, 'search'])
                ->name('search');

            Route::get('/by-genre/{genre}', [\Modules\Catalogue\Http\Controllers\CategoryController::class, 'byGenre'])
                ->name('by-genre')
                ->where('genre', 'homme|femme|enfant|mixte');

            // Liste principale
            Route::get('/', [\Modules\Catalogue\Http\Controllers\CategoryController::class, 'index'])
                ->name('index');

            Route::get('/{slug}', [\Modules\Catalogue\Http\Controllers\CategoryController::class, 'show'])
                ->name('show')
                ->where('slug', '[a-z0-9-]+');

            Route::get('/{slug}/products', [\Modules\Catalogue\Http\Controllers\CategoryController::class, 'products'])
                ->name('products')
                ->where('slug', '[a-z0-9-]+');

            Route::get('/{slug}/breadcrumb', [\Modules\Catalogue\Http\Controllers\CategoryController::class, 'breadcrumb'])
                ->name('breadcrumb')
                ->where('slug', '[a-z0-9-]+');

            Route::get('/{slug}/related', [\Modules\Catalogue\Http\Controllers\CategoryController::class, 'related'])
                ->name('related')
                ->where('slug', '[a-z0-9-]+');
        });

        // Brands
        Route::get('/brands', [\Modules\Catalogue\Http\Controllers\BrandController::class, 'index'])
            ->name('brands.index');
    });

    // Gift Carts (public access via token)
    Route::prefix('gift-carts')->name('api.gift-carts.')->middleware('throttle:60,1')->group(function () {
        Route::get('/{token}', [\Modules\Cart\Http\Controllers\GiftCartController::class, 'show'])
            ->name('show');
        Route::post('/{token}/contribute', [\Modules\Cart\Http\Controllers\GiftCartController::class, 'contribute'])
            ->name('contribute');
        Route::get('/{token}/contributions', [\Modules\Cart\Http\Controllers\GiftCartController::class, 'contributions'])
            ->name('contributions');
    });

    // Payment Webhooks (signature verification in middleware)
    Route::prefix('webhooks')->name('api.webhooks.')->middleware('throttle:60,1')->group(function () {
        Route::post('/fedapay', [\Modules\Payment\Http\Controllers\PaymentWebhookController::class, 'fedapay'])
            ->middleware('fedapay.signature')
            ->name('fedapay');
    });
});
