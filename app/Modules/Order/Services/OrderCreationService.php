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

            $channel = $data['channel'] ?? Order::CHANNEL_ONLINE;
            $isWhatsapp = $channel === Order::CHANNEL_WHATSAPP;

            $order = Order::create([
                'customer_id' => $cart->customer_id,
                'status' => $isWhatsapp ? Order::STATUS_WHATSAPP_SENT : Order::STATUS_PENDING,
                'payment_status' => Order::PAYMENT_STATUS_PENDING,
                'payment_method' => $data['payment_method'] ?? ($isWhatsapp ? 'whatsapp' : null),
                'channel' => $channel,
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

            OrderStatusHistory::createHistory(
                $order->id,
                $isWhatsapp ? Order::STATUS_WHATSAPP_SENT : Order::STATUS_PENDING,
                $isWhatsapp ? 'Commande créée et envoyée sur WhatsApp' : 'Commande créée'
            );

            if ($isWhatsapp) {
                // Cahier des charges §9 : génère le lien WhatsApp avec un message pré-rempli
                // (numéro de commande, récapitulatif des produits, total estimé).
                $order->update([
                    'metadata' => array_merge($order->metadata ?? [], [
                        'whatsapp_url' => $this->buildWhatsappUrl($order->fresh(['items'])),
                    ]),
                ]);
            } elseif (($data['payment_method'] ?? null) === Payment::GATEWAY_FEDAPAY) {
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

    /**
     * Cahier des charges §9 : construit le lien "wa.me" avec le message pré-rempli.
     *
     * Exemple de message :
     *   Bonjour BYLIN,
     *   Je souhaite finaliser la commande n°BYL-000125.
     *   Produits :
     *   - Blazer Signature Noir x1
     *   Total estimé : 135 000 FCFA.
     */
    protected function buildWhatsappUrl(Order $order): ?string
    {
        $number = preg_replace('/\D+/', '', (string) config('services.whatsapp.number'));

        $lines = [
            'Bonjour BYLIN,',
            '',
            "Je souhaite finaliser la commande n°{$order->order_number}.",
            '',
            'Produits :',
        ];

        foreach ($order->items as $item) {
            $name = $item->product_name . ($item->variation_name ? " ({$item->variation_name})" : '');
            $lines[] = "- {$name} x{$item->quantity}";
        }

        $lines[] = '';
        $lines[] = 'Total estimé : ' . number_format((int) $order->total, 0, ',', ' ') . ' FCFA.';

        $text = rawurlencode(implode("\n", $lines));

        // Sans numéro configuré, on retourne tout de même un lien wa.me générique
        // que le frontend peut compléter, plutôt que de casser le parcours.
        return $number !== ''
            ? "https://wa.me/{$number}?text={$text}"
            : "https://wa.me/?text={$text}";
    }
}
