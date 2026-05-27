<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\BaseService;
use Modules\Inventory\Services\InventoryService;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderStatusHistory;

class OrderService extends BaseService
{
    public function __construct(
        protected InventoryService $inventoryService,
    ) {}

    /**
     * Get order by ID.
     */
    public function getOrder(string $id): Order
    {
        return Order::with(['items.product', 'items.variation', 'statusHistories'])
            ->findOrFail($id);
    }

    /**
     * Update order status with basic transition guards.
     */
    public function updateStatus(Order $order, string $status, ?string $note = null, ?string $userId = null): Order
    {
        if ($order->status === $status) {
            return $order;
        }

        if ($order->status === Order::STATUS_CANCELLED) {
            throw new \DomainException('Une commande annulée ne peut plus changer de statut.');
        }

        if ($order->status === Order::STATUS_REFUNDED && $status !== Order::STATUS_REFUNDED) {
            throw new \DomainException('Une commande remboursée ne peut plus changer de statut.');
        }

        if ($status === Order::STATUS_DELIVERED && $order->payment_status !== Order::PAYMENT_STATUS_PAID) {
            throw new \DomainException('Une commande ne peut pas être livrée tant que le paiement n’est pas payé.');
        }

        return DB::transaction(function () use ($order, $status, $note, $userId) {
            $order->update(['status' => $status]);

            OrderStatusHistory::createHistory($order->id, $status, $note, $userId);

            return $order->fresh(['items.product', 'items.variation', 'statusHistories']);
        });
    }

    /**
     * Update payment status.
     */
    public function updatePaymentStatus(Order $order, string $status): Order
    {
        $order->update(['payment_status' => $status]);

        return $order->fresh();
    }

    /**
     * Cancel order and release reserved stock exactly once.
     */
    public function cancelOrder(Order $order, ?string $reason = null, ?string $userId = null): Order
    {
        if (! $order->canBeCancelled()) {
            throw new \DomainException('La commande ne peut pas être annulée dans son état actuel.');
        }

        return DB::transaction(function () use ($order, $reason, $userId) {
            $lockedOrder = Order::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($order->id);

            if (! $lockedOrder->canBeCancelled()) {
                throw new \DomainException('La commande ne peut pas être annulée dans son état actuel.');
            }

            $this->inventoryService->releaseOrderStock($lockedOrder);

            $lockedOrder->update(['status' => Order::STATUS_CANCELLED]);

            OrderStatusHistory::createHistory(
                $lockedOrder->id,
                Order::STATUS_CANCELLED,
                $reason ?? 'Commande annulée',
                $userId
            );

            return $lockedOrder->fresh(['items.product', 'items.variation', 'statusHistories']);
        });
    }
}
