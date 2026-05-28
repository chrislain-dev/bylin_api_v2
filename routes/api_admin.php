<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->middleware(['auth:sanctum', 'admin.auth', 'throttle:60,1'])
    ->name('api.admin.')
    ->group(function () {

        // Authentification
        Route::get('/me', [\Modules\User\Http\Controllers\AuthController::class, 'me'])->name('me');
        Route::post('/logout', [\Modules\User\Http\Controllers\AuthController::class, 'logout'])->name('logout');
        Route::post('/refresh', [\Modules\User\Http\Controllers\AuthController::class, 'refresh'])->name('refresh');

        // Management Utilisateurs
        Route::prefix('users')->name('users.')->group(function () {
            Route::get('/super-admins/count', [\Modules\User\Http\Controllers\UserController::class, 'countSuperAdmins'])
                ->middleware('permission:users.view')
                ->name('super-admins.count');
        });

        Route::apiResource('users', \Modules\User\Http\Controllers\UserController::class)
            ->middlewareFor(['index', 'show'], 'permission:users.view')
            ->middlewareFor('store', 'permission:users.create')
            ->middlewareFor('update', 'permission:users.update')
            ->middlewareFor('destroy', 'permission:users.delete');

        // Profile management
        Route::prefix('profile')->name('profile.')->group(function () {
            Route::get('/', [\Modules\User\Http\Controllers\ProfileController::class, 'show'])->name('show');
            Route::put('/', [\Modules\User\Http\Controllers\ProfileController::class, 'update'])->name('update');
            Route::post('/avatar', [\Modules\User\Http\Controllers\ProfileController::class, 'uploadAvatar'])->name('avatar.upload');
            Route::delete('/avatar', [\Modules\User\Http\Controllers\ProfileController::class, 'deleteAvatar'])->name('avatar.delete');
            Route::post('/change-password', [\Modules\User\Http\Controllers\ProfileController::class, 'changePassword'])->name('password.change');
            Route::delete('/', [\Modules\User\Http\Controllers\ProfileController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('authenticity')->name('authenticity.')->middleware('permission:authenticity.manage')->group(function () {
            Route::post('/generate', [\Modules\Catalogue\Http\Controllers\AuthenticityController::class, 'generate'])->name('generate');
            Route::get('/analytics', [\Modules\Catalogue\Http\Controllers\AuthenticityController::class, 'analytics'])->name('analytics');
            Route::put('/{qrCode}/mark-fake', [\Modules\Catalogue\Http\Controllers\AuthenticityController::class, 'markAsFake'])->name('mark-fake');
            Route::get('/product/{productId}/stats', [\Modules\Catalogue\Http\Controllers\AuthenticityController::class, 'productStats'])->name('product-stats');
        });

        // Collections utilities (outside resourceful routes)
        Route::get('collections-seasons', [\Modules\Catalogue\Http\Controllers\Admin\CollectionController::class, 'seasons'])->middleware('permission:catalogue.view')->name('collections.seasons');
        Route::get('collections-featured', [\Modules\Catalogue\Http\Controllers\Admin\CollectionController::class, 'featured'])->middleware('permission:catalogue.view')->name('collections.featured');

        Route::get('collections/products/available', [\Modules\Catalogue\Http\Controllers\Admin\CollectionController::class, 'availableProducts'])->middleware('permission:catalogue.view')->name('collections.products.available');
        Route::post('collections/products/bulk-move', [\Modules\Catalogue\Http\Controllers\Admin\CollectionController::class, 'bulkMoveProducts'])->middleware('permission:catalogue.update')->name('collections.products.bulk-move');


        Route::apiResource('collections', \Modules\Catalogue\Http\Controllers\Admin\CollectionController::class)
            ->middlewareFor(['index', 'show'], 'permission:catalogue.view')
            ->middlewareFor('store', 'permission:catalogue.create')
            ->middlewareFor('update', 'permission:catalogue.update')
            ->middlewareFor('destroy', 'permission:catalogue.delete');

        Route::prefix('collections')->name('collections.')->middleware('permission:catalogue.update')->group(function () {

            // Toggle statuses
            Route::post('{id}/toggle-featured', [\Modules\Catalogue\Http\Controllers\Admin\CollectionController::class, 'toggleFeatured'])
                ->name('toggle-featured');
            Route::post('{id}/toggle-active', [\Modules\Catalogue\Http\Controllers\Admin\CollectionController::class, 'toggleActive'])
                ->name('toggle-active');

            // Gestion des produits
            Route::post('{id}/products/add', [\Modules\Catalogue\Http\Controllers\Admin\CollectionController::class, 'addProducts'])
                ->name('products.add');
            Route::post('{id}/products/remove', [\Modules\Catalogue\Http\Controllers\Admin\CollectionController::class, 'removeProducts'])
                ->name('products.remove');
            Route::post('{id}/products/sync', [\Modules\Catalogue\Http\Controllers\Admin\CollectionController::class, 'syncProducts'])
                ->name('products.sync');
            Route::get('{id}/products/statistics', [\Modules\Catalogue\Http\Controllers\Admin\CollectionController::class, 'productsStatistics'])
                ->name('products.statistics');

            // Statistics & analytics
            Route::get('{id}/statistics', [\Modules\Catalogue\Http\Controllers\Admin\CollectionController::class, 'statistics'])
                ->name('statistics');

            // Maintenance
            Route::post('{id}/refresh-counts', [\Modules\Catalogue\Http\Controllers\Admin\CollectionController::class, 'refreshCounts'])
                ->name('refresh-counts');
            Route::post('{id}/archive', [\Modules\Catalogue\Http\Controllers\Admin\CollectionController::class, 'archive'])
                ->name('archive');
        });

        Route::prefix('products')->name('products.')->group(function () {
            // Statistics (doit être avant {id})
            Route::get('statistics', [\Modules\Catalogue\Http\Controllers\Admin\ProductController::class, 'statistics'])->middleware('permission:catalogue.view')->name('statistics');

            // Bulk operations
            Route::post('bulk/update', [\Modules\Catalogue\Http\Controllers\Admin\ProductController::class, 'bulkUpdate'])->middleware('permission:catalogue.update')->name('bulk.update');
            Route::post('bulk/destroy', [\Modules\Catalogue\Http\Controllers\Admin\ProductController::class, 'bulkDestroy'])->middleware('permission:catalogue.delete')->name('bulk.destroy');
            Route::post('bulk/restore', [\Modules\Catalogue\Http\Controllers\Admin\ProductController::class, 'bulkRestore'])->middleware('permission:catalogue.update')->name('bulk.restore');
            Route::post('bulk/force-delete', [\Modules\Catalogue\Http\Controllers\Admin\ProductController::class, 'bulkForceDelete'])->middleware('permission:catalogue.delete')->name('bulk.force-delete');

            // Export
            Route::post('export', [\Modules\Catalogue\Http\Controllers\Admin\ProductController::class, 'export'])->middleware('permission:catalogue.view')->name('export');

            // Routes spécifiques avec {id}
            Route::post('{id}/duplicate', [\Modules\Catalogue\Http\Controllers\Admin\ProductController::class, 'duplicate'])->middleware('permission:catalogue.create')->name('duplicate');
            Route::post('{id}/restore', [\Modules\Catalogue\Http\Controllers\Admin\ProductController::class, 'restore'])->middleware('permission:catalogue.update')->name('restore');
            Route::delete('{id}/force', [\Modules\Catalogue\Http\Controllers\Admin\ProductController::class, 'forceDelete'])->middleware('permission:catalogue.delete')->name('force-delete');

            // Stock management
            Route::post('{id}/stock', [\Modules\Catalogue\Http\Controllers\Admin\ProductController::class, 'updateStock'])->middleware('permission:inventory.manage')->name('update-stock');
            Route::get('{id}/stock-history', [\Modules\Catalogue\Http\Controllers\Admin\ProductController::class, 'stockHistory'])->middleware('permission:inventory.manage')->name('stock-history');
            Route::post('{productId}/variations/{variationId}/stock', [\Modules\Catalogue\Http\Controllers\Admin\ProductController::class, 'updateVariationStock'])->middleware('permission:inventory.manage')->name('variations.update-stock');

            // Preorder management
            Route::post('{id}/enable-preorder', [\Modules\Catalogue\Http\Controllers\Admin\ProductController::class, 'enablePreorder'])->middleware('permission:catalogue.update')->name('enable-preorder');
            Route::post('{id}/disable-preorder', [\Modules\Catalogue\Http\Controllers\Admin\ProductController::class, 'disablePreorder'])->middleware('permission:catalogue.update')->name('disable-preorder');
            Route::get('{id}/preorder-info', [\Modules\Catalogue\Http\Controllers\Admin\ProductController::class, 'preorderInfo'])->middleware('permission:catalogue.view')->name('preorder-info');
            Route::get('{id}/authenticity/stats', [\Modules\Catalogue\Http\Controllers\Admin\ProductController::class, 'authenticityStats'])->middleware('permission:authenticity.manage')->name('authenticity.stats');
        });
        Route::apiResource('products', \Modules\Catalogue\Http\Controllers\Admin\ProductController::class)
            ->middlewareFor(['index', 'show'], 'permission:catalogue.view')
            ->middlewareFor('store', 'permission:catalogue.create')
            ->middlewareFor('update', 'permission:catalogue.update')
            ->middlewareFor('destroy', 'permission:catalogue.delete');


        Route::prefix('categories')->name('categories.')->group(function () {
            Route::get('tree', [\Modules\Catalogue\Http\Controllers\Admin\CategoryController::class, 'tree'])->middleware('permission:catalogue.view')->name('tree');
            Route::patch('{id}/move', [\Modules\Catalogue\Http\Controllers\Admin\CategoryController::class, 'move'])->middleware('permission:catalogue.update')->name('move');
            Route::post('reorder', [\Modules\Catalogue\Http\Controllers\Admin\CategoryController::class, 'reorder'])->middleware('permission:catalogue.update')->name('reorder');
            Route::post('{id}/restore', [\Modules\Catalogue\Http\Controllers\Admin\CategoryController::class, 'restore'])->middleware('permission:catalogue.update')->name('restore');
            Route::get('statistics', [\Modules\Catalogue\Http\Controllers\Admin\CategoryController::class, 'statistics'])->middleware('permission:catalogue.view')->name('statistics');
            Route::get('{id}/breadcrumb', [\Modules\Catalogue\Http\Controllers\Admin\CategoryController::class, 'breadcrumb'])->middleware('permission:catalogue.view')->name('breadcrumb');
            Route::delete('{id}/force', [\Modules\Catalogue\Http\Controllers\Admin\CategoryController::class, 'forceDelete'])->middleware('permission:catalogue.delete')->name('force-delete');

            Route::prefix('bulk')->name('bulk.')->group(function () {
                Route::post('destroy', [\Modules\Catalogue\Http\Controllers\Admin\CategoryController::class, 'bulkDestroy'])->middleware('permission:catalogue.delete')->name('destroy');
                Route::post('restore', [\Modules\Catalogue\Http\Controllers\Admin\CategoryController::class, 'bulkRestore'])->middleware('permission:catalogue.update')->name('restore');
                Route::post('force-delete', [\Modules\Catalogue\Http\Controllers\Admin\CategoryController::class, 'bulkForceDelete'])->middleware('permission:catalogue.delete')->name('force-delete');
            });
        });
        Route::apiResource('categories', \Modules\Catalogue\Http\Controllers\Admin\CategoryController::class)
            ->middlewareFor(['index', 'show'], 'permission:catalogue.view')
            ->middlewareFor('store', 'permission:catalogue.create')
            ->middlewareFor('update', 'permission:catalogue.update')
            ->middlewareFor('destroy', 'permission:catalogue.delete');

        Route::post('/brands/{id}/restore', [\Modules\Catalogue\Http\Controllers\Admin\BrandController::class, 'restore'])->middleware('permission:catalogue.update')->name('brands.restore');
        Route::get('/brands/statistics', [\Modules\Catalogue\Http\Controllers\Admin\BrandController::class, 'statistics'])->middleware('permission:catalogue.view')->name('brands.statistics');
        Route::delete('/brands/{id}/force', [\Modules\Catalogue\Http\Controllers\Admin\BrandController::class, 'forceDelete'])->middleware('permission:catalogue.delete')->name('brands.force-delete');

        Route::prefix('brands/bulk')->name('brands.bulk.')->group(function () {
            Route::post('destroy', [\Modules\Catalogue\Http\Controllers\Admin\BrandController::class, 'bulkDestroy'])->middleware('permission:catalogue.delete')->name('destroy');
            Route::post('restore', [\Modules\Catalogue\Http\Controllers\Admin\BrandController::class, 'bulkRestore'])->middleware('permission:catalogue.update')->name('restore');
            Route::post('force-delete', [\Modules\Catalogue\Http\Controllers\Admin\BrandController::class, 'bulkForceDelete'])->middleware('permission:catalogue.delete')->name('force-delete');
        });

        Route::post('/brands/{brand}', [\Modules\Catalogue\Http\Controllers\Admin\BrandController::class, 'update'])->middleware('permission:catalogue.update')->name('brands.update-with-files');
        Route::apiResource('brands', \Modules\Catalogue\Http\Controllers\Admin\BrandController::class)
            ->middlewareFor(['index', 'show'], 'permission:catalogue.view')
            ->middlewareFor('store', 'permission:catalogue.create')
            ->middlewareFor('update', 'permission:catalogue.update')
            ->middlewareFor('destroy', 'permission:catalogue.delete');

        Route::prefix('attributes')->name('attributes.')->group(function () {
            Route::get('statistics', [\Modules\Catalogue\Http\Controllers\Admin\AttributeController::class, 'statistics'])->middleware('permission:catalogue.view')->name('statistics');
            Route::post('reorder', [\Modules\Catalogue\Http\Controllers\Admin\AttributeController::class, 'reorder'])->middleware('permission:catalogue.update')->name('reorder');
            Route::post('{id}/restore', [\Modules\Catalogue\Http\Controllers\Admin\AttributeController::class, 'restore'])->middleware('permission:catalogue.update')->name('restore');
            Route::delete('{id}/force', [\Modules\Catalogue\Http\Controllers\Admin\AttributeController::class, 'forceDelete'])->middleware('permission:catalogue.delete')->name('force-delete');

            Route::prefix('bulk')->name('bulk.')->group(function () {
                Route::post('destroy', [\Modules\Catalogue\Http\Controllers\Admin\AttributeController::class, 'bulkDestroy'])->middleware('permission:catalogue.delete')->name('destroy');
                Route::post('restore', [\Modules\Catalogue\Http\Controllers\Admin\AttributeController::class, 'bulkRestore'])->middleware('permission:catalogue.update')->name('restore');
                Route::post('force-delete', [\Modules\Catalogue\Http\Controllers\Admin\AttributeController::class, 'bulkForceDelete'])->middleware('permission:catalogue.delete')->name('force-delete');
            });
        });
        Route::apiResource('attributes', \Modules\Catalogue\Http\Controllers\Admin\AttributeController::class)
            ->middlewareFor(['index', 'show'], 'permission:catalogue.view')
            ->middlewareFor('store', 'permission:catalogue.create')
            ->middlewareFor('update', 'permission:catalogue.update')
            ->middlewareFor('destroy', 'permission:catalogue.delete');

        Route::prefix('products/{productId}/preorder')->name('products.preorder.')->middleware('permission:catalogue.update')->group(function () {
            Route::post('/enable', [\Modules\Catalogue\Http\Controllers\Admin\PreorderController::class, 'enable'])->name('enable');
            Route::post('/disable', [\Modules\Catalogue\Http\Controllers\Admin\PreorderController::class, 'disable'])->name('disable');
        });
        Route::get('/preorders', [\Modules\Catalogue\Http\Controllers\Admin\PreorderController::class, 'index'])->middleware('permission:catalogue.view')->name('preorders.index');

        // Order Management
        Route::apiResource('orders', \Modules\Order\Http\Controllers\Admin\OrderController::class)
            ->only(['index', 'show', 'destroy'])
            ->middlewareFor(['index', 'show'], 'permission:orders.view')
            ->middlewareFor('destroy', 'permission:orders.delete');
        Route::get('/orders/{id}/items', [\Modules\Order\Http\Controllers\Admin\OrderController::class, 'items'])
            ->middleware('permission:orders.view')
            ->name('orders.items');
        Route::put('/orders/{id}/status', [\Modules\Order\Http\Controllers\Admin\OrderController::class, 'updateStatus'])
            ->middleware('permission:orders.update')
            ->name('orders.update-status');
        Route::post('/orders/{id}/cancel', [\Modules\Order\Http\Controllers\Admin\OrderController::class, 'cancel'])
            ->middleware('permission:orders.cancel')
            ->name('orders.cancel');

        // Payment Management
        Route::apiResource('payments', \Modules\Payment\Http\Controllers\Admin\PaymentController::class)
            ->only(['index', 'show'])
            ->middlewareFor(['index', 'show'], 'permission:payments.view');
        Route::post('/payments/{id}/refund', [\Modules\Payment\Http\Controllers\Admin\PaymentController::class, 'refund'])
            ->middleware('permission:payments.refund')
            ->name('payments.refund');

        // Customer Management
        Route::get('customers/statistics', [\Modules\Customer\Http\Controllers\Admin\CustomerController::class, 'statistics'])
            ->middleware('permission:customers.view')
            ->name('customers.statistics');
        Route::apiResource('customers', \Modules\Customer\Http\Controllers\Admin\CustomerController::class)
            ->middlewareFor(['index', 'show'], 'permission:customers.view')
            ->middlewareFor('store', 'permission:customers.create')
            ->middlewareFor('update', 'permission:customers.update')
            ->middlewareFor('destroy', 'permission:customers.delete');

        // Customer status management
        Route::prefix('customers')->name('customers.')->group(function () {

            // Status management
            Route::patch('{id}/suspend', [\Modules\Customer\Http\Controllers\Admin\CustomerController::class, 'suspend'])->middleware('permission:customers.update')->name('suspend');
            Route::patch('{id}/activate', [\Modules\Customer\Http\Controllers\Admin\CustomerController::class, 'activate'])->middleware('permission:customers.update')->name('activate');
            Route::patch('{id}/deactivate', [\Modules\Customer\Http\Controllers\Admin\CustomerController::class, 'deactivate'])->middleware('permission:customers.update')->name('deactivate');

            // Bulk status update
            Route::post('bulk/status', [\Modules\Customer\Http\Controllers\Admin\CustomerController::class, 'bulkUpdateStatus'])->middleware('permission:customers.update')->name('bulk.status');

            // Soft delete & restore
            Route::post('{id}/restore', [\Modules\Customer\Http\Controllers\Admin\CustomerController::class, 'restore'])->middleware('permission:customers.update')->name('restore');
            Route::post('bulk/restore', [\Modules\Customer\Http\Controllers\Admin\CustomerController::class, 'bulkRestore'])->middleware('permission:customers.update')->name('bulk.restore');
            Route::post('bulk/destroy', [\Modules\Customer\Http\Controllers\Admin\CustomerController::class, 'bulkDestroy'])->middleware('permission:customers.delete')->name('bulk.destroy');

            // Force delete
            Route::delete('{id}/force', [\Modules\Customer\Http\Controllers\Admin\CustomerController::class, 'forceDelete'])->middleware('permission:customers.delete')->name('force-delete');
            Route::post('bulk/force-delete', [\Modules\Customer\Http\Controllers\Admin\CustomerController::class, 'bulkForceDelete'])->middleware('permission:customers.delete')->name('bulk.force-delete');

            // Export
            Route::post('export', [\Modules\Customer\Http\Controllers\Admin\CustomerController::class, 'export'])->middleware('permission:customers.view')->name('export');

        });

        Route::prefix('settings')->name('settings.')->group(function () {
            // ========================================================================
            // MEMBERS STATISTICS
            // ========================================================================
            Route::get('members/statistics', [\Modules\Settings\Http\Controllers\Admin\SettingsMemberController::class, 'statistics'])
                ->middleware('permission:settings.view')
                ->name('members.statistics');

            // ========================================================================
            // MEMBERS MANAGEMENT
            // ========================================================================
            Route::prefix('members')->name('members.')->group(function () {
                Route::get('', [\Modules\Settings\Http\Controllers\Admin\SettingsMemberController::class, 'index'])
                    ->middleware('permission:settings.view')
                    ->name('index');
                Route::post('', [\Modules\Settings\Http\Controllers\Admin\SettingsMemberController::class, 'store'])
                    ->middleware('permission:settings.update')
                    ->name('store');

                Route::prefix('{member}')->whereUuid('member')->group(function () {
                    Route::get('', [\Modules\Settings\Http\Controllers\Admin\SettingsMemberController::class, 'show'])
                        ->middleware('permission:settings.view')
                        ->name('show');
                    Route::put('', [\Modules\Settings\Http\Controllers\Admin\SettingsMemberController::class, 'update'])
                        ->middleware('permission:settings.update')
                        ->name('update');
                    Route::delete('', [\Modules\Settings\Http\Controllers\Admin\SettingsMemberController::class, 'destroy'])
                        ->middleware('permission:settings.update')
                        ->name('destroy');
                    Route::patch('role', [\Modules\Settings\Http\Controllers\Admin\SettingsMemberController::class, 'updateRole'])
                        ->middleware('permission:settings.update')
                        ->name('update-role');
                    Route::patch('status', [\Modules\Settings\Http\Controllers\Admin\SettingsMemberController::class, 'updateStatus'])
                        ->middleware('permission:settings.update')
                        ->name('update-status');
                });
            });

            // ========================================================================
            // INVITATIONS
            // ========================================================================
            Route::prefix('invitations')->name('invitations.')->group(function () {
                Route::get('', [\Modules\Settings\Http\Controllers\Admin\SettingsMemberController::class, 'invitations'])
                    ->middleware('permission:settings.view')
                    ->name('index');
                Route::post('', [\Modules\Settings\Http\Controllers\Admin\SettingsMemberController::class, 'invite'])
                    ->middleware('permission:settings.update')
                    ->name('store');
                Route::post('bulk', [\Modules\Settings\Http\Controllers\Admin\SettingsMemberController::class, 'bulkInvite'])
                    ->middleware('permission:settings.update')
                    ->name('bulk');
                Route::post('{id}/resend', [\Modules\Settings\Http\Controllers\Admin\SettingsMemberController::class, 'resendInvitation'])
                    ->middleware('permission:settings.update')
                    ->whereUuid('id')
                    ->name('resend');
                Route::delete('{id}', [\Modules\Settings\Http\Controllers\Admin\SettingsMemberController::class, 'cancelInvitation'])
                    ->middleware('permission:settings.update')
                    ->whereUuid('id')
                    ->name('cancel');
            });
        });

        // Promotion Management
        Route::get('/promotions/statistics', [\Modules\Promotion\Http\Controllers\Admin\PromotionController::class, 'statistics'])->middleware('permission:promotions.view')->name('promotions.statistics');
        Route::post('/promotions/bulk/destroy', [\Modules\Promotion\Http\Controllers\Admin\PromotionController::class, 'bulkDestroy'])->middleware('permission:promotions.delete')->name('promotions.bulk.destroy');
        Route::post('/promotions/bulk/restore', [\Modules\Promotion\Http\Controllers\Admin\PromotionController::class, 'bulkRestore'])->middleware('permission:promotions.update')->name('promotions.bulk.restore');
        Route::post('/promotions/{id}/restore', [\Modules\Promotion\Http\Controllers\Admin\PromotionController::class, 'restore'])->middleware('permission:promotions.update')->name('promotions.restore');
        Route::apiResource('promotions', \Modules\Promotion\Http\Controllers\Admin\PromotionController::class)
            ->middlewareFor(['index', 'show'], 'permission:promotions.view')
            ->middlewareFor('store', 'permission:promotions.create')
            ->middlewareFor('update', 'permission:promotions.update')
            ->middlewareFor('destroy', 'permission:promotions.delete');

        // Review Management
        Route::prefix('reviews')->name('reviews.')->middleware('permission:reviews.manage')->group(function () {
            Route::get('statistics', [\Modules\Reviews\Http\Controllers\Admin\ReviewController::class, 'statistics'])->name('statistics');
            Route::post('bulk/approve', [\Modules\Reviews\Http\Controllers\Admin\ReviewController::class, 'bulkApprove'])->name('bulk.approve');
            Route::post('bulk/reject', [\Modules\Reviews\Http\Controllers\Admin\ReviewController::class, 'bulkReject'])->name('bulk.reject');
            Route::post('bulk/destroy', [\Modules\Reviews\Http\Controllers\Admin\ReviewController::class, 'bulkDestroy'])->name('bulk.destroy');
            Route::post('bulk/restore', [\Modules\Reviews\Http\Controllers\Admin\ReviewController::class, 'bulkRestore'])->name('bulk.restore');
            Route::post('{id}/restore', [\Modules\Reviews\Http\Controllers\Admin\ReviewController::class, 'restore'])->name('restore');
        });
        Route::apiResource('reviews', \Modules\Reviews\Http\Controllers\Admin\ReviewController::class)
            ->only(['index', 'show', 'destroy'])
            ->middleware('permission:reviews.manage');
        Route::post('/reviews/{id}/approve', [\Modules\Reviews\Http\Controllers\Admin\ReviewController::class, 'approve'])->middleware('permission:reviews.manage')->name('reviews.approve');
        Route::post('/reviews/{id}/reject', [\Modules\Reviews\Http\Controllers\Admin\ReviewController::class, 'reject'])->middleware('permission:reviews.manage')->name('reviews.reject');

        // Shipping Management
        Route::apiResource('shipments', \Modules\Shipping\Http\Controllers\Admin\ShipmentController::class)
            ->middlewareFor(['index', 'show'], 'permission:shipping.view')
            ->middlewareFor('store', 'permission:shipping.create')
            ->middlewareFor('update', 'permission:shipping.update')
            ->middlewareFor('destroy', 'permission:shipping.delete');
        Route::apiResource('shipping-methods', \Modules\Shipping\Http\Controllers\Admin\ShippingMethodController::class)
            ->middlewareFor(['index', 'show'], 'permission:shipping.view')
            ->middlewareFor('store', 'permission:shipping.create')
            ->middlewareFor('update', 'permission:shipping.update')
            ->middlewareFor('destroy', 'permission:shipping.delete');

        // Inventory Management
        Route::prefix('inventory')->name('inventory.')->middleware('permission:inventory.manage')->group(function () {

            // Stock queries
            Route::get('notification-settings', [\Modules\Inventory\Http\Controllers\Admin\InventoryNotificationSettingsController::class, 'show'])->name('notification-settings.show');
            Route::put('notification-settings', [\Modules\Inventory\Http\Controllers\Admin\InventoryNotificationSettingsController::class, 'update'])->name('notification-settings.update');
            Route::get('low-stock', [\Modules\Inventory\Http\Controllers\Admin\InventoryController::class, 'lowStock'])->name('low-stock');
            Route::get('movements', [\Modules\Inventory\Http\Controllers\Admin\InventoryController::class, 'movements'])->name('movements');
            Route::get('statistics', [\Modules\Inventory\Http\Controllers\Admin\InventoryController::class, 'statistics'])->name('statistics');
            Route::get('out-of-stock', [\Modules\Inventory\Http\Controllers\Admin\InventoryController::class, 'outOfStock'])->name('out-of-stock');

        // Stock adjustments
            Route::get('', [\Modules\Inventory\Http\Controllers\Admin\InventoryController::class, 'index'])->name('index');
            Route::post('adjust', [\Modules\Inventory\Http\Controllers\Admin\InventoryController::class, 'adjust'])->name('adjust');
            Route::post('bulk-adjust', [\Modules\Inventory\Http\Controllers\Admin\InventoryController::class, 'bulkAdjust'])->name('bulk-adjust');

            // Export
            Route::post('export', [\Modules\Inventory\Http\Controllers\Admin\InventoryController::class, 'export'])->name('export');
        });

        // Dashboard & Analytics
        Route::get('/dashboard/stats', [\Modules\Core\Http\Controllers\DashboardController::class, 'stats'])->middleware('permission:reports.view')->name('dashboard.stats');
    });
