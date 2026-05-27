<?php

declare(strict_types=1);

namespace Modules\Order\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;
use Modules\Order\Http\Requests\UpdateOrderStatusRequest;
use Modules\Order\Http\Requests\CancelOrderAdminRequest;

class OrderController extends ApiController
{
    protected OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * List all orders
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::with(['customer', 'items']);

        // Filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_email', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                  });
            });
        }

        $orders = $query->latest()->paginate($request->per_page ?? 20);

        return $this->successResponse($orders);
    }

    /**
     * Show order details
     */
    public function show(string $id): JsonResponse
    {
        $order = Order::with([
            'customer', 
            'items.product', 
            'items.variation', 
            'statusHistories.user', 
            'payment', 
            'shipment'
        ])->findOrFail($id);

        return $this->successResponse($order);
    }

    /**
     * Update order status
     */
    public function updateStatus(string $id, UpdateOrderStatusRequest $request): JsonResponse
    {
        $order = Order::findOrFail($id);

        try {
            $order = $this->orderService->updateStatus(
                $order, 
                $request->status, 
                $request->note, 
                auth()->id()
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse($order, 'Statut de la commande mis à jour');
    }

    /**
     * Cancel order
     */
    public function cancel(string $id, CancelOrderAdminRequest $request): JsonResponse
    {
        $order = Order::findOrFail($id);

        try {
            $order = $this->orderService->cancelOrder(
                $order, 
                $request->reason, 
                auth()->id()
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse($order, 'Commande annulée avec succès');
    }

    /**
     * List order items.
     */
    public function items(string $id): JsonResponse
    {
        $order = Order::with(['items.product', 'items.variation'])->findOrFail($id);

        return $this->successResponse($order->items);
    }

    /**
     * Soft delete an order only when it is already cancelled or refunded.
     */
    public function destroy(string $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        if (! in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_REFUNDED], true)) {
            return $this->errorResponse('Seules les commandes annulées ou remboursées peuvent être supprimées.', 422);
        }

        $order->delete();

        return $this->successResponse(null, 'Commande supprimée avec succès');
    }

}
