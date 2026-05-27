<?php

declare(strict_types=1);

namespace Modules\Cart\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\BaseModel;
use Modules\Customer\Models\Customer;

class Cart extends BaseModel
{
    use HasUuids;

    protected $table = 'carts';

    protected $fillable = [
        'customer_id',
        'session_id',
        'coupon_code',
        'discount_amount',
        'subtotal',
        'tax_amount',
        'shipping_amount',
        'total',
        'metadata',
        'expires_at',

        'is_gift_cart',
        'gift_cart_token',
        'gift_cart_status',
        'gift_cart_target_amount',
        'gift_cart_paid_amount',
        'gift_cart_owner_id',
        'gift_cart_message',
        'gift_cart_expires_at',
    ];

    protected $casts = [
        'discount_amount' => 'integer',
        'subtotal' => 'integer',
        'tax_amount' => 'integer',
        'shipping_amount' => 'integer',
        'total' => 'integer',
        'metadata' => 'array',
        'expires_at' => 'datetime',

        'is_gift_cart' => 'boolean',
        'gift_cart_target_amount' => 'integer',
        'gift_cart_paid_amount' => 'integer',
        'gift_cart_expires_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function giftCartOwner(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'gift_cart_owner_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function contributors(): HasMany
    {
        return $this->hasMany(GiftCartContributor::class, 'gift_cart_id');
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    public function scopeGuest($query)
    {
        return $query->whereNull('customer_id')
                     ->whereNotNull('session_id');
    }

    public function scopeForCustomer($query, string $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeForSession($query, string $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    public function isEmpty(): bool
    {
        return $this->items()->count() === 0;
    }

    public function isExpired(): bool
    {
        return $this->expires_at?->isPast() ?? false;
    }

    public function getTotalItemsAttribute(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function calculateSubtotal(): float
    {
        return (float) $this->items->sum('subtotal');
    }

    public function calculateTotal(): float
    {
        return (float) max(0, $this->subtotal + $this->tax_amount + $this->shipping_amount - $this->discount_amount);
    }

    public function setExpiration(?int $days = null): void
    {
        if ($this->customer_id) {
            $this->expires_at = null;
        } else {
            $expirationDays = $days ?? config('cart.guest_cart_expiration_days', 30);
            $this->expires_at = now()->addDays($expirationDays);
        }

        $this->save();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($cart) {
            if (! $cart->customer_id && ! $cart->expires_at) {
                $cart->expires_at = now()->addDays(
                    config('cart.guest_cart_expiration_days', 30)
                );
            }
        });
    }
}
