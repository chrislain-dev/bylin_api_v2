<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customer API Routes
|--------------------------------------------------------------------------
| Routes for customer users - requires authentication
*/

Route::prefix('v1/customer')
    ->middleware(['web', 'auth:sanctum', 'customer.auth', 'throttle:120,1'])
    ->name('api.customer.')
    ->group(function () {
    
    // Authentication
    Route::post('/logout', [\Modules\Customer\Http\Controllers\CustomerAuthController::class, 'logout'])
        ->name('logout');
    Route::get('/me', [\Modules\Customer\Http\Controllers\CustomerAuthController::class, 'me'])
        ->name('me');
    
    // Profile Management
    Route::put('/profile', [\Modules\Customer\Http\Controllers\CustomerController::class, 'updateProfile'])
        ->name('profile.update');
    Route::post('/profile/change-password', [\Modules\Customer\Http\Controllers\CustomerController::class, 'changePassword'])
        ->name('profile.change-password');
    
    // Address Management
    Route::apiResource('addresses', \Modules\Customer\Http\Controllers\AddressController::class);
    Route::post('/addresses/{id}/set-default', [\Modules\Customer\Http\Controllers\AddressController::class, 'setDefault'])
        ->name('addresses.set-default');
    
    // Cart Management
    Route::get('/cart', [\Modules\Cart\Http\Controllers\CartController::class, 'show'])
        ->name('cart.show');
    Route::post('/cart/items', [\Modules\Cart\Http\Controllers\CartController::class, 'addItem'])
        ->name('cart.add-item');
    Route::put('/cart/items/{itemId}', [\Modules\Cart\Http\Controllers\CartController::class, 'updateItem'])
        ->name('cart.update-item');
    Route::delete('/cart/items/{itemId}', [\Modules\Cart\Http\Controllers\CartController::class, 'removeItem'])
        ->name('cart.remove-item');
    Route::delete('/cart', [\Modules\Cart\Http\Controllers\CartController::class, 'clear'])
        ->name('cart.clear');
    Route::post('/cart/coupon', [\Modules\Cart\Http\Controllers\CartController::class, 'applyCoupon'])
        ->name('cart.apply-coupon');
    Route::delete('/cart/coupon', [\Modules\Cart\Http\Controllers\CartController::class, 'removeCoupon'])
        ->name('cart.remove-coupon');
    
    // Gift Cart
    Route::post('/cart/convert-to-gift', [\Modules\Cart\Http\Controllers\GiftCartController::class, 'convert'])
        ->name('cart.convert-gift');
    Route::get('/gift-carts', [\Modules\Cart\Http\Controllers\GiftCartController::class, 'myGiftCarts'])
        ->name('gift-carts.index');
    Route::post('/gift-carts/{token}/cancel', [\Modules\Cart\Http\Controllers\GiftCartController::class, 'cancel'])
        ->name('gift-carts.cancel');
    
    // Orders
    Route::post('/orders', [\Modules\Order\Http\Controllers\OrderController::class, 'create'])
        ->name('orders.create');
    Route::get('/orders', [\Modules\Order\Http\Controllers\OrderController::class, 'index'])
        ->name('orders.index');
    Route::get('/orders/{id}', [\Modules\Order\Http\Controllers\OrderController::class, 'show'])
        ->name('orders.show');
    Route::post('/orders/{id}/cancel', [\Modules\Order\Http\Controllers\OrderController::class, 'cancel'])
        ->name('orders.cancel');
    
    // Preorders
    Route::get('/preorders', [\Modules\Order\Http\Controllers\OrderController::class, 'preorders'])
        ->name('preorders.index');
    
    // Reviews
    Route::post('/reviews', [\Modules\Reviews\Http\Controllers\ReviewController::class, 'store'])
        ->name('reviews.store');
    Route::get('/reviews', [\Modules\Reviews\Http\Controllers\ReviewController::class, 'myReviews'])
        ->name('reviews.index');
    Route::put('/reviews/{id}', [\Modules\Reviews\Http\Controllers\ReviewController::class, 'update'])
        ->name('reviews.update');
    Route::delete('/reviews/{id}', [\Modules\Reviews\Http\Controllers\ReviewController::class, 'destroy'])
        ->name('reviews.destroy');
    
    // Wishlist
    Route::get('/wishlist', [\Modules\Customer\Http\Controllers\WishlistController::class, 'index'])
        ->name('wishlist.index');
    Route::post('/wishlist', [\Modules\Customer\Http\Controllers\WishlistController::class, 'add'])
        ->name('wishlist.add');
    Route::delete('/wishlist', [\Modules\Customer\Http\Controllers\WishlistController::class, 'clear'])
        ->name('wishlist.clear');
    Route::get('/wishlist/{productId}/check', [\Modules\Customer\Http\Controllers\WishlistController::class, 'check'])
        ->whereUuid('productId')
        ->name('wishlist.check');
    Route::delete('/wishlist/{productId}', [\Modules\Customer\Http\Controllers\WishlistController::class, 'remove'])
        ->whereUuid('productId')
        ->name('wishlist.remove');
    
    // Notifications
    Route::get('/notifications', [\Modules\Notification\Http\Controllers\NotificationController::class, 'index'])
        ->name('notifications.index');
    Route::get('/notifications/unread-count', [\Modules\Notification\Http\Controllers\NotificationController::class, 'unreadCount'])
        ->name('notifications.unread-count');
    Route::post('/notifications/read-all', [\Modules\Notification\Http\Controllers\NotificationController::class, 'markAllAsRead'])
        ->name('notifications.read-all');
    Route::post('/notifications/{id}/read', [\Modules\Notification\Http\Controllers\NotificationController::class, 'markAsRead'])
        ->whereUuid('id')
        ->name('notifications.read');
});
