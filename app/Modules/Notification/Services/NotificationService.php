<?php

declare(strict_types=1);

namespace Modules\Notification\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Notification\Jobs\SendEmailNotification;
use Modules\Notification\Models\Notification;

class NotificationService
{
    /**
     * Create and dispatch a notification for a notifiable model.
     */
    public function notify(
        Model $user,
        string $type,
        string $title,
        string $message,
        array $data = [],
        array $channels = ['database'],
        string $priority = 'normal'
    ): Notification {
        $channels = array_values(array_unique($channels));

        $notification = Notification::create([
            'notifiable_id' => $user->id,
            'notifiable_type' => $user::class,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'channel' => implode(',', $channels),
            'priority' => $priority,
            'data' => $data,
            'action_url' => $data['action_url'] ?? null,
            'action_text' => $data['action_text'] ?? null,
            'icon' => $data['icon'] ?? null,
            'metadata' => ['channels' => $channels],
            'status' => Notification::STATUS_PENDING,
        ]);

        if (in_array('database', $channels, true)) {
            $notification->markAsSent();
        }

        if (in_array('email', $channels, true)) {
            SendEmailNotification::dispatch($notification);
        }

        return $notification;
    }

    public function orderConfirmation(Model $customer, $order): void
    {
        $this->notify(
            $customer,
            Notification::TYPE_ORDER_CONFIRMATION,
            'Commande confirmée',
            "Votre commande #{$order->order_number} a été confirmée et est en cours de traitement.",
            [
                'order_id' => $order->id,
                'order_total' => $order->total,
                'action_url' => route('api.customer.orders.show', $order->id),
                'action_text' => 'Voir la commande',
            ],
            ['database', 'email'],
            'high'
        );
    }

    public function paymentSuccess(Model $customer, $order, int|float $amount): void
    {
        $this->notify(
            $customer,
            Notification::TYPE_PAYMENT_SUCCESS,
            'Paiement réussi',
            "Votre paiement de {$amount} a été traité avec succès.",
            [
                'order_id' => $order->id,
                'amount' => $amount,
            ],
            ['database', 'email'],
            'high'
        );
    }

    public function paymentFailed(Model $customer, $order, string $reason): void
    {
        $this->notify(
            $customer,
            Notification::TYPE_PAYMENT_FAILED,
            'Paiement échoué',
            "Votre paiement n'a pas pu être traité. Raison : {$reason}",
            [
                'order_id' => $order->id,
                'reason' => $reason,
                'action_url' => route('api.customer.orders.show', $order->id),
                'action_text' => 'Réessayer le paiement',
            ],
            ['database', 'email'],
            'urgent'
        );
    }

    public function orderShipped(Model $customer, $order, ?string $trackingNumber = null): void
    {
        $message = "Votre commande #{$order->order_number} a été expédiée.";

        if ($trackingNumber) {
            $message .= " Numéro de suivi : {$trackingNumber}.";
        }

        $this->notify(
            $customer,
            Notification::TYPE_ORDER_SHIPPED,
            'Commande expédiée',
            $message,
            [
                'order_id' => $order->id,
                'tracking_number' => $trackingNumber,
                'action_url' => route('api.customer.orders.show', $order->id),
                'action_text' => 'Suivre la commande',
            ],
            ['database', 'email']
        );
    }

    public function newDeviceAlert(Model $user, array $deviceInfo, array $location): void
    {
        $deviceName = $deviceInfo['device_name'] ?? 'appareil inconnu';
        $city = $location['city'] ?? 'localisation inconnue';
        $country = $location['country'] ?? '';

        $this->notify(
            $user,
            'new_device_login',
            'Nouvelle connexion détectée',
            "Une nouvelle connexion depuis {$deviceName} à {$city} {$country} a été détectée.",
            [
                'device' => $deviceInfo,
                'location' => $location,
                'icon' => 'shield-exclamation',
            ],
            ['database', 'email'],
            'high'
        );
    }

    public function markAsRead(string $notificationId): void
    {
        Notification::query()->find($notificationId)?->markAsRead();
    }

    public function markAllAsRead(Model $user): int
    {
        return Notification::forNotifiable($user::class, (string) $user->id)
            ->unread()
            ->update(['read_at' => now()]);
    }

    public function getUnreadCount(Model $user): int
    {
        return Notification::forNotifiable($user::class, (string) $user->id)
            ->unread()
            ->count();
    }

    public function getRecent(Model $user, int $limit = 10)
    {
        return Notification::forNotifiable($user::class, (string) $user->id)
            ->latest()
            ->limit(min($limit, 50))
            ->get();
    }
}
