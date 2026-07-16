<?php

declare(strict_types=1);

namespace Modules\Customer\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Catalogue\Models\Product;
use Modules\Core\Models\BaseModel;

class Wishlist extends BaseModel
{
    protected $table = 'wishlists';

    protected $fillable = [
        'customer_id',
        'product_id',
        'notes',
    ];

    /**
     * Get the customer
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the product
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Scope for customer
     */
    public function scopeForCustomer($query, string $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Scope for product
     */
    public function scopeForProduct($query, string $productId)
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Check if product is in customer's wishlist
     */
    public static function hasProduct(string $customerId, string $productId): bool
    {
        return self::where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->exists();
    }

    /**
     * Add product to wishlist
     */
    public static function addProduct(string $customerId, string $productId, ?string $notes = null): self
    {
        return self::firstOrCreate(
            [
                'customer_id' => $customerId,
                'product_id' => $productId,
            ],
            [
                'notes' => $notes,
            ]
        );
    }

    /**
     * Remove product from wishlist
     */
    public static function removeProduct(string $customerId, string $productId): bool
    {
        return self::where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->delete();
    }
}
