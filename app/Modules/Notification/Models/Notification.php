<?php

declare(strict_types=1);

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Core\Models\BaseModel;

class Notification extends BaseModel
{
    use HasUuids;

    protected $table = 'notifications';

    protected $fillable = [
        'type',
        'title',
        'message',
        'notifiable_type',
        'notifiable_id',
        'channel',
        'status',
        'data',
        'action_url',
        'action_text',
        'icon',
        'priority',
        'metadata',
        'sent_at',
        'read_at',
        'error_message',
    ];

    protected $casts = [
        'data' => 'array',
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    // Type constants
    public const TYPE_ORDER_CONFIRMATION = 'order_confirmation';
    public const TYPE_ORDER_SHIPPED = 'order_shipped';
    public const TYPE_ORDER_DELIVERED = 'order_delivered';
    public const TYPE_ORDER_CANCELLED = 'order_cancelled';
    public const TYPE_PAYMENT_SUCCESS = 'payment_success';
    public const TYPE_PAYMENT_FAILED = 'payment_failed';
    public const TYPE_REVIEW_REQUEST = 'review_request';
    public const TYPE_PROMOTION = 'promotion';
    public const TYPE_STOCK_ALERT = 'stock_alert';
    public const TYPE_GENERAL = 'general';

    // Channel constants
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_PUSH = 'push';
    public const CHANNEL_DATABASE = 'database';

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /**
     * Get the notifiable entity (Customer or User)
     */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope for a specific notifiable
     */
    public function scopeForNotifiable($query, string $type, string $id)
    {
        return $query->where('notifiable_type', $type)
                     ->where('notifiable_id', $id);
    }

    /**
     * Scope for unread notifications
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope for read notifications
     */
    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Scope for pending notifications
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope for sent notifications
     */
    public function scopeSent($query)
    {
        return $query->where('status', self::STATUS_SENT);
    }

    /**
     * Scope by channel
     */
    public function scopeChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope by type
     */
    public function scopeType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(): self
    {
        if (!$this->read_at) {
            $this->read_at = now();
            $this->save();
        }

        return $this;
    }

    /**
     * Mark notification as unread
     */
    public function markAsUnread(): self
    {
        $this->read_at = null;
        $this->save();

        return $this;
    }

    /**
     * Mark as sent
     */
    public function markAsSent(): self
    {
        $this->status = self::STATUS_SENT;
        $this->sent_at = now();
        $this->save();

        return $this;
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(string $errorMessage): self
    {
        $this->status = self::STATUS_FAILED;
        $this->error_message = $errorMessage;
        $this->save();

        return $this;
    }

    /**
     * Check if notification is read
     */
    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Check if notification is sent
     */
    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }
}
