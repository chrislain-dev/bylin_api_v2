<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use Illuminate\Support\Facades\DB;
use Modules\Cart\Models\Cart;
use Modules\Cart\Services\CartService;
use Modules\Core\Services\BaseService;
use Modules\Inventory\Services\InventoryService;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\OrderStatusHistory;
use Modules\Payment\Services\PaymentService;
use Modules\Payment\Models\Payment;
use Modules\Promotion\Services\PromotionService;

class OrderCreationService extends BaseService
{
    protected CartService $cartService;
    protected InventoryService $inventoryService;
    protected PromotionService $promotionService;
    protected PaymentService $paymentService;

    public function __construct(
        CartService $cartService,
        InventoryService $inventoryService,
        PromotionService $promotionService,
        PaymentService $paymentService
    ) {
        $this->cartService = $cartService;
        $this->inventoryService = $inventoryService;
        $this->promotionService = $promotionService;
        $this->paymentService = $paymentService;
    }

    /**
     * Create order from cart
     */
    public function createOrderFromCart(Cart $cart, array $data): Order
    {
        \Log::info('=== CREATE ORDER FROM CART START ===');
        \Log::info('Cart data:', ['cart_id' => $cart->id, 'customer_id' => $cart->customer_id]);
        \Log::info('Order data:', $data);
        
        return DB::transaction(function () use ($cart, $data) {
            // 1. Validate cart
            \Log::info('Step 1: Validating cart');
            if ($cart->items->isEmpty()) {
                \Log::error('Cart is empty');
                throw new \Exception('Cart is empty');
            }
            \Log::info('Cart has items:', ['count' => $cart->items->count()]);

            // 2. Validate stock for all items
            \Log::info('Step 2: Validating stock');
            foreach ($cart->items as $item) {
                \Log::info('Checking stock for item:', [
                    'product_id' => $item->product_id,
                    'variation_id' => $item->variation_id,
                    'quantity' => $item->quantity
                ]);
                if (!$this->inventoryService->checkStock($item->product_id, $item->quantity, $item->variation_id)) {
                    \Log::error('Insufficient stock:', ['product' => $item->product->name]);
                    throw new \Modules\Core\Exceptions\OutOfStockException("Insufficient stock for item: {$item->product->name}");
                }
            }
            \Log::info('Stock validation passed');

            // 3. Create Order
            \Log::info('Step 3: Creating order');
            $order = Order::create([
                'customer_id' => $cart->customer_id,
                'status' => Order::STATUS_PENDING,
                'payment_status' => Order::PAYMENT_STATUS_PENDING,
                'payment_method' => $data['payment_method'] ?? null,
                'customer_email' => $data['customer_email'], // Snapshot email
                'customer_phone' => $data['customer_phone'], // Snapshot phone
                'shipping_address' => $data['shipping_address'],
                'billing_address' => $data['billing_address'] ?? $data['shipping_address'],
                'subtotal' => $cart->subtotal,
                'discount_amount' => $cart->discount_amount,
                'tax_amount' => $cart->tax_amount,
                'shipping_amount' => $cart->shipping_amount,
                'total' => $cart->total,
                'coupon_code' => $cart->coupon_code,
                'customer_note' => $data['customer_note'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);
            \Log::info('Order created:', ['order_id' => $order->id]);

            // 4. Create Order Items and Reserve Stock
            \Log::info('Step 4: Creating order items and reserving stock');
            foreach ($cart->items as $item) {
                \Log::info('Creating order item:', [
                    'product_id' => $item->product_id,
                    'variation_id' => $item->variation_id,
                    'quantity' => $item->quantity
                ]);
                
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
                    'discount_amount' => 0, // Item level discount logic if needed
                    'total' => $item->subtotal,
                    'options' => $item->options,
                ]);

                // Reserve stock
                \Log::info('Reserving stock:', [
                    'product_id' => $item->product_id,
                    'variation_id' => $item->variation_id,
                    'quantity' => $item->quantity,
                    'order_id' => $order->id
                ]);
                $this->inventoryService->reserveStock(
                    $item->product_id,
                    $item->quantity,
                    $item->variation_id,
                    $order->id
                );
            }
            \Log::info('Order items created and stock reserved');

            // 5. Record Promotion Usage if applicable
            if ($cart->coupon_code) {
                $promotion = $this->promotionService->validateCoupon($cart->coupon_code, $cart);
                $this->promotionService->recordUsage(
                    $promotion,
                    $order->id,
                    $cart->customer_id,
                    $cart->discount_amount
                );
            }

            // 6. Record Initial Status History
            \Log::info('Step 6: Recording status history');
            OrderStatusHistory::createHistory($order->id, Order::STATUS_PENDING, 'Order created');

            // 7. Initialize Payment (for FedaPay)
            \Log::info('Step 7: Initializing payment if needed');
            if ($data['payment_method'] === 'fedapay') {
                try {
                    \Log::info('Initializing FedaPay payment');
                    $paymentData = $this->paymentService->initializePayment($order, Payment::GATEWAY_FEDAPAY);
                    
                    // Store payment URL and token in order metadata
                    $metadata = $order->metadata ?? [];
                    $metadata['payment_url'] = $paymentData['payment_url'];
                    $metadata['payment_token'] = $paymentData['token'];
                    $metadata['transaction_reference'] = $paymentData['transaction_reference'];
                    $order->update(['metadata' => $metadata]);
                    
                    \Log::info('FedaPay payment initialized', [
                        'payment_url' => $paymentData['payment_url'],
                        'transaction_ref' => $paymentData['transaction_reference']
                    ]);
                    
                    // Store payment URL for response
                    $order->payment_url = $paymentData['payment_url'];
                } catch (\Exception $e) {
                    \Log::error('FedaPay payment initialization failed', [
                        'error' => $e->getMessage(),
                        'order_id' => $order->id
                    ]);
                    // Don't fail the entire order, but log the error
                    // Order will remain in pending payment status
                    $order->payment_url = null;
                }
            }

            // 8. Clear Cart
            \Log::info('Step 8: Clearing cart');
            $this->cartService->clearCart($cart);

            return $order;
        });
    }
}
