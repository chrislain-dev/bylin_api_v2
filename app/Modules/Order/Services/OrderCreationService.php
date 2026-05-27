<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use Illuminate\Support\Facades\DB;
use Modules\Cart\Models\Cart;
use Modules\Cart\Services\CartService;
use Modules\Core\Exceptions\OutOfStockException;
use Modules\Core\Services\BaseService;
use Modules\Inventory\Services\InventoryService;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\OrderStatusHistory;
use Modules\Payment\Models\Payment;
use Modules\Payment\Services\PaymentService;
use Modules\Promotion\Services\PromotionService;

class OrderCreationService extends BaseService
{
    public function __construct(
        protected CartService $cartService,
        protected InventoryService $inventoryService,
        protected PromotionService $promotionService,
        protected PaymentService $paymentService,
    ) {}

    /**
     * Create order from cart.
     */
    public function createOrderFromCart(Cart $cart, array $data): Order
    {
        return DB::transaction(function () use ($cart, $data) {
            $cart->loadMissing(['items.product', 'items.variation']);

            if ($cart->items->isEmpty()) {
                throw new \DomainException('Le panier est vide.');
            }

            foreach ($cart->items as $item) {
                if (! $this->inventoryService->checkStock($item->product_id, (int) $item->quantity, $item->variation_id)) {
                    throw new OutOfStockException("Stock insuffisant pour l’article : {$item->product->name}");
                }
            }

            $order = Order::create([
                'customer_id' => $cart->customer_id,
                'status' => Order::STATUS_PENDING,
                'payment_status' => Order::PAYMENT_STATUS_PENDING,
                'payment_method' => $data['payment_method'] ?? null,
                'customer_email' => $data['customer_email'],
                'customer_phone' => $data['customer_phone'],
                'shipping_address' => $data['shipping_address'],
                'billing_address' => $data['billing_address'] ?? $data['shipping_address'],
                'subtotal' => $cart->subtotal,
                'discount_amount' => $cart->discount_amount,
                'tax_amount' => $cart->tax_amount,
                'shipping_amount' => $cart->shipping_amount,
                'total' => $cart->total,
                'coupon_code' => $cart->coupon_code,
                'customer_note' => $data['customer_note'] ?? null,
                'metadata' => $data['metadata'] ?? [],
            ]);

            foreach ($cart->items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'variation_id' => $item->variation_id,
                    'product_name' => $item->product->name,
                    'product_sku' => $item->variation ? $item->variation->sku : $item->product->sku,
                    'variation_name' => $item->variation ? $item->variation->variation_name : null,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'subtotal' => $item->subtotal,
                    'discount_amount' => 0,
                    'total' => $item->subtotal,
                    'is_preorder' => (bool) ($item->is_preorder ?? false),
                    'expected_availability_date' => $item->expected_availability_date ?? null,
                    'preorder_status' => $item->preorder_status ?? null,
                    'options' => $item->options,
                ]);

                $this->inventoryService->reserveStock(
                    $item->product_id,
                    (int) $item->quantity,
                    $item->variation_id,
                    $order->id,
                );
            }

            if ($cart->coupon_code) {
                $promotion = $this->promotionService->validateCoupon($cart->coupon_code, $cart);
                $this->promotionService->recordUsage(
                    $promotion,
                    $order->id,
                    $cart->customer_id,
                    $cart->discount_amount,
                );
            }

            OrderStatusHistory::createHistory($order->id, Order::STATUS_PENDING, 'Commande créée');

            if (($data['payment_method'] ?? null) === Payment::GATEWAY_FEDAPAY) {
                $paymentData = $this->paymentService->initializePayment($order, Payment::GATEWAY_FEDAPAY);

                $order->update([
                    'metadata' => array_merge($order->metadata ?? [], [
                        'payment_url' => $paymentData['payment_url'],
                        'payment_token' => $paymentData['token'],
                        'payment_id' => $paymentData['payment_id'],
                        'transaction_reference' => $paymentData['transaction_reference'],
                    ]),
                ]);
            }

            $this->cartService->clearCart($cart);

            return $order->fresh(['items.product', 'items.variation', 'payment']);
        });
    }
}
