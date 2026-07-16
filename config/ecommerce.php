<?php

return [
    /*
    |--------------------------------------------------------------------------
    | E-commerce Configuration
    |--------------------------------------------------------------------------
    */

    'gift_cart' => [
        'enabled' => env('GIFT_CART_ENABLED', true),
        'default_expiration_days' => env('GIFT_CART_EXPIRATION_DAYS', 30),
        // Cahier des charges §11 : contribution minimale de 30% de la valeur du panier
        'min_contribution_percentage' => env('GIFT_CART_MIN_CONTRIBUTION', 30),
        'allow_anonymous_contributors' => env('GIFT_CART_ALLOW_ANONYMOUS', true),
        // Cahier des charges §11 : aucun remboursement à expiration,
        // les contributions sont converties en Avoir BYLIN (crédit boutique)
        'refund_on_expiration' => env('GIFT_CART_REFUND_ON_EXPIRATION', false),
        'convert_to_store_credit_on_expiration' => env('GIFT_CART_STORE_CREDIT_ON_EXPIRATION', true),
    ],

    'preorder' => [
        'enabled' => env('PREORDER_ENABLED', true),
        'auto_enable_on_out_of_stock' => env('PREORDER_AUTO_ENABLE', true),
        'default_wait_period_days' => env('PREORDER_WAIT_DAYS', 30),
        'allow_cancellation' => env('PREORDER_ALLOW_CANCEL', true),
        'payment_on_order' => env('PREORDER_PAYMENT_ON_ORDER', true),
    ],

    'cart' => [
        'session_lifetime_days' => env('CART_SESSION_LIFETIME', 30),
        'merge_on_login' => env('CART_MERGE_ON_LOGIN', true),
        'guest_carts_enabled' => env('CART_GUEST_ENABLED', true),
    ],

    'order' => [
        'number_prefix' => env('ORDER_NUMBER_PREFIX', 'ORD'),
        'auto_cancel_pending_after_hours' => env('ORDER_AUTO_CANCEL_HOURS', 24),
        'allow_customer_cancellation' => env('ORDER_ALLOW_CUSTOMER_CANCEL', true),
    ],

    'authenticity' => [
        'enabled' => env('AUTHENTICITY_ENABLED', true),
        'qr_code_prefix' => env('AUTHENTICITY_QR_PREFIX', 'BYLIN'),
        'track_scan_location' => env('AUTHENTICITY_TRACK_LOCATION', false),
    ],

    'payment' => [
        'default_gateway' => env('PAYMENT_GATEWAY', 'fedapay'),
        'default_currency' => env('PAYMENT_CURRENCY', 'XOF'),
        'test_mode' => env('PAYMENT_TEST_MODE', true),
        
        'fedapay' => [
            'public_key' => env('FEDAPAY_PUBLIC_KEY'),
            'secret_key' => env('FEDAPAY_SECRET_KEY'),
            'webhook_secret' => env('FEDAPAY_WEBHOOK_SECRET'),
            'environment' => env('FEDAPAY_ENVIRONMENT', 'sandbox'),
        ],
    ],

    'shipping' => [
        'default_method' => env('SHIPPING_DEFAULT_METHOD'),
        'free_shipping_threshold' => env('SHIPPING_FREE_THRESHOLD'),
    ],

    'reviews' => [
        'moderation_enabled' => env('REVIEWS_MODERATION', true),
        'require_purchase' => env('REVIEWS_REQUIRE_PURCHASE', false),
        'allow_media' => env('REVIEWS_ALLOW_MEDIA', true),
        'max_media_per_review' => env('REVIEWS_MAX_MEDIA', 5),
    ],

    'inventory' => [
        'track_by_default' => env('INVENTORY_TRACK_DEFAULT', true),
        'allow_backorders' => env('INVENTORY_ALLOW_BACKORDERS', false),
        'low_stock_threshold' => env('INVENTORY_LOW_STOCK', 10),
    ],
];
