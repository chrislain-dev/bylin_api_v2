<?php

declare(strict_types=1);

namespace Modules\Customer\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Core\Traits\HasStatus;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customer Model
 *
 * @property string $id
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string $phone
 * @property string $status
 * @property array $preferences
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable, HasStatus, HasRoles, SoftDeletes;

    /**
     * The guard name
     */
    protected $guard = 'customer';

    /**
     * The primary key type
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'date_of_birth',
        'gender',
        'avatar',
        'avatar_url',
        'preferences',
        'oauth_provider',
        'oauth_provider_id',
        'status',
    ];

    /**
     * The attributes that should be hidden
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'date_of_birth' => 'date',
            'preferences' => 'array',
        ];
    }

    /**
     * Get available statuses
     */
    public function getAvailableStatuses(): array
    {
        return ['active', 'inactive', 'suspended'];
    }

    /**
     * Get customer's full name
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Customer addresses
     */
    public function addresses()
    {
        return $this->hasMany(Address::class);
    }


    /**
     * Customer carts
     */
    public function carts()
    {
        return $this->hasMany(\Modules\Cart\Models\Cart::class);
    }

    /**
     * Customer wishlist items
     */
    public function wishlistItems()
    {
        return $this->hasMany(Wishlist::class);
    }

    /**
     * Get default shipping address
     */
    public function defaultShippingAddress()
    {
        return $this->hasOne(Address::class)
            ->where('type', 'shipping')
            ->where('is_default', true);
    }

    /**
     * Get default billing address
     */
    public function defaultBillingAddress()
    {
        return $this->hasOne(Address::class)
            ->where('type', 'billing')
            ->where('is_default', true);
    }

    /**
     * Update customer status safely
     */
    public function updateStatus(string $status): bool
    {
        if (!in_array($status, $this->getAvailableStatuses())) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }

        $this->status = $status;
        return $this->save();
    }

    /**
     * Activate customer account
     */
    public function activate(): bool
    {
        return $this->updateStatus('active');
    }

    /**
     * Deactivate customer account
     */
    public function deactivate(): bool
    {
        return $this->updateStatus('inactive');
    }

    /**
     * Suspend customer account
     */
    public function suspend(): bool
    {
        return $this->updateStatus('suspended');
    }

    /**
     * Check if customer is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if customer is suspended
     */
    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * Check if customer has an OAuth provider linked
     */
    public function hasOAuthProvider(): bool
    {
        return !empty($this->oauth_provider) && !empty($this->oauth_provider_id);
    }

    /**
     * Link OAuth provider to this customer
     */
    public function linkOAuthProvider(string $provider, string $providerId, ?string $avatarUrl = null): void
    {
        $this->oauth_provider = $provider;
        $this->oauth_provider_id = $providerId;

        if ($avatarUrl) {
            $this->avatar_url = $avatarUrl;
        }

        // Mark email as verified for OAuth users
        if (!$this->email_verified_at) {
            $this->email_verified_at = now();
        }

        $this->save();
    }

    /**
     * Update customer information safely
     */
    public function updateInformation(array $data): bool
    {
        $allowed = ['first_name', 'last_name', 'phone', 'date_of_birth', 'gender', 'preferences'];
        $filtered = array_intersect_key($data, array_flip($allowed));

        return $this->update($filtered);
    }

    /**
     * Get customer data for export
     */
    public function toExportArray(): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'date_of_birth' => $this->date_of_birth?->format('d/m/Y'),
            'gender' => $this->gender,
            'email_verified_at' => $this->email_verified_at?->format('d/m/Y H:i:s'),
            'created_at' => $this->created_at->format('d/m/Y H:i:s'),
            'updated_at' => $this->updated_at->format('d/m/Y H:i:s'),
        ];
    }

    /**
     * Scope for searching customers
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (!$term) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%");
        });
    }

    /**
     * Scope for active customers only
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for inactive customers only
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Scope for suspended customers only
     */
    public function scopeSuspended(Builder $query): Builder
    {
        return $query->where('status', 'suspended');
    }

    /**
     * Boot the model
     */
    protected static function boot(): void
    {
        parent::boot();

        // Automatically set status to inactive when soft deleting
        static::deleting(function ($customer) {
            if (!$customer->isForceDeleting()) {
                $customer->status = 'inactive';
                $customer->saveQuietly();
            }
        });

        // Restore status to active when restoring
        static::restoring(function ($customer) {
            $customer->status = 'active';
        });
    }
}
