<?php

declare(strict_types=1);

namespace Modules\Order\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Cart\Services\CartService;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Order\Http\Requests\CancelOrderRequest;
use Modules\Order\Http\Requests\StoreOrderRequest;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderCreationService;
use Modules\Order\Services\OrderService;

class OrderController extends ApiController
{
    public function __construct(
        protected OrderService $orderService,
        protected OrderCreationService $orderCreationService,
        protected CartService $cartService,
    ) {}

    /**
     * Get customer orders.
     */
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with(['items.product', 'statusHistories'])
            ->where('customer_id', $request->user()->id)
            ->latest()
            ->paginate((int) $request->input('per_page', 15));

        return $this->successResponse($orders);
    }

    /**
     * Get order details.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $order = Order::with(['items.product', 'items.variation', 'statusHistories', 'payment', 'shipment'])
            ->where('customer_id', $request->user()->id)
            ->findOrFail($id);

        return $this->successResponse($order);
    }

    /**
     * Create new order from cart.
     */
    public function create(StoreOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $customerId = $request->user()->id;
        $cart = $this->cartService->getCart($customerId);

        if ($cart->isEmpty()) {
            return $this->errorResponse('Le panier est vide.', 400);
        }

        try {
            $order = $this->orderCreationService->createOrderFromCart($cart, $validated)
                ->load(['items.product', 'items.variation', 'payment']);
        } catch (\Throwable $e) {
            Log::warning('Order creation failed', [
                'customer_id' => $customerId,
                'message' => $e->getMessage(),
            ]);

            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse($order, 'Commande créée avec succès.', 201);
    }

    /**
     * Cancel order.
     */
    public function cancel(CancelOrderRequest $request, string $id): JsonResponse
    {
        $order = Order::where('customer_id', $request->user()->id)
            ->findOrFail($id);

        try {
            $order = $this->orderService->cancelOrder($order, $request->validated('reason'), $request->user()->id);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse($order, 'Commande annulée avec succès.');
    }

    /**
     * Get customer orders containing preorder products.
     */
    public function preorders(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with(['items.product', 'items.variation', 'statusHistories'])
            ->where('customer_id', $request->user()->id)
            ->whereHas('items.product', function ($query) {
                $query->where('is_preorder_enabled', true)
                    ->orWhere('status', 'preorder');
            })
            ->latest()
            ->paginate((int) $request->input('per_page', 15));

        return $this->successResponse($orders);
    }
}
