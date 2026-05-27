<?php

declare(strict_types=1);

namespace Modules\Cart\Services;

use Illuminate\Support\Facades\DB;
use Modules\Cart\Models\Cart;
use Modules\Catalogue\Models\Product;
use Modules\Catalogue\Models\ProductVariation;
use Modules\Core\Exceptions\BusinessException;
use Modules\Core\Exceptions\OutOfStockException;
use Modules\Core\Services\BaseService;
use Modules\Promotion\Services\PromotionService;

class CartService extends BaseService
{
    public function __construct(
        protected PromotionService $promotionService
    ) {}

    public function getCart(?string $customerId = null, ?string $sessionId = null): Cart
    {
        if ($customerId) {
            $cart = Cart::forCustomer($customerId)->active()->first();

            if (! $cart) {
                $cart = Cart::create(['customer_id' => $customerId]);
            }

            return $cart->load('items.product.brand', 'items.product.categories', 'items.variation');
        }

        if (! $sessionId) {
            throw new BusinessException('A session identifier is required for guest carts.');
        }

        $cart = Cart::forSession($sessionId)->active()->first();

        if (! $cart) {
            $cart = Cart::create(['session_id' => $sessionId]);
        }

        return $cart->load('items.product.brand', 'items.product.categories', 'items.variation');
    }

    public function addItem(Cart $cart, array $data): Cart
    {
        return DB::transaction(function () use ($cart, $data) {
            $productId = $data['product_id'];
            $variationId = $data['variation_id'] ?? null;
            $quantity = (int) ($data['quantity'] ?? 1);
            $options = $data['options'] ?? null;

            $product = Product::query()->lockForUpdate()->findOrFail($productId);

            $status = $product->status instanceof \BackedEnum ? $product->status->value : (string) $product->status;

            if (! in_array($status, ['active', 'preorder'], true)) {
                throw new BusinessException('This product is not available for purchase.');
            }

            $price = (int) $product->price;
            $availableStock = (int) $product->stock_quantity;

            if ($variationId) {
                $variation = ProductVariation::query()
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->findOrFail($variationId);

                if (! $variation->is_active) {
                    throw new BusinessException('This product variation is not available.');
                }

                $price = (int) $variation->price;
                $availableStock = (int) $variation->stock_quantity;
            }

            $existingItem = $cart->items()
                ->where('product_id', $productId)
                ->when($variationId, fn ($query) => $query->where('variation_id', $variationId))
                ->when(! $variationId, fn ($query) => $query->whereNull('variation_id'))
                ->lockForUpdate()
                ->first();

            $requestedQuantity = $quantity + (int) ($existingItem?->quantity ?? 0);

            if ($product->track_inventory && $availableStock < $requestedQuantity) {
                throw new OutOfStockException("Insufficient stock. Available: {$availableStock}");
            }

            if ($existingItem) {
                $existingItem->update([
                    'quantity' => $requestedQuantity,
                    'price' => $price,
                    'options' => $options ?? $existingItem->options,
                ]);
            } else {
                $cart->items()->create([
                    'product_id' => $productId,
                    'variation_id' => $variationId,
                    'quantity' => $quantity,
                    'price' => $price,
                    'options' => $options,
                ]);
            }

            return $this->recalculateCart($cart);
        });
    }

    public function updateItem(Cart $cart, string $itemId, int $quantity): Cart
    {
        return DB::transaction(function () use ($cart, $itemId, $quantity) {
            $item = $cart->items()->with('product', 'variation')->lockForUpdate()->findOrFail($itemId);

            if ($quantity <= 0) {
                $item->delete();
                return $this->recalculateCart($cart);
            }

            $product = Product::query()->lockForUpdate()->findOrFail($item->product_id);
            $stock = (int) $product->stock_quantity;

            if ($item->variation_id) {
                $variation = ProductVariation::query()
                    ->where('product_id', $item->product_id)
                    ->lockForUpdate()
                    ->findOrFail($item->variation_id);

                $stock = (int) $variation->stock_quantity;
            }

            if ($product->track_inventory && $stock < $quantity) {
                throw new OutOfStockException("Insufficient stock. Available: {$stock}");
            }

            $item->updateQuantity($quantity);

            return $this->recalculateCart($cart);
        });
    }

    public function removeItem(Cart $cart, string $itemId): Cart
    {
        return DB::transaction(function () use ($cart, $itemId) {
            $cart->items()->where('id', $itemId)->delete();
            return $this->recalculateCart($cart);
        });
    }

    public function clearCart(Cart $cart): void
    {
        DB::transaction(function () use ($cart) {
            $cart->items()->delete();
            $cart->update([
                'coupon_code' => null,
                'discount_amount' => 0,
                'shipping_amount' => 0,
                'tax_amount' => 0,
                'subtotal' => 0,
                'total' => 0,
            ]);
        });
    }

    public function mergeCarts(Cart $guestCart, Cart $customerCart): Cart
    {
        return DB::transaction(function () use ($guestCart, $customerCart) {
            foreach ($guestCart->items as $item) {
                $this->addItem($customerCart, [
                    'product_id' => $item->product_id,
                    'variation_id' => $item->variation_id,
                    'quantity' => $item->quantity,
                    'options' => $item->options,
                ]);
            }

            $guestCart->delete();

            return $this->recalculateCart($customerCart);
        });
    }

    public function applyCoupon(Cart $cart, string $code): Cart
    {
        return DB::transaction(function () use ($cart, $code) {
            $cart = $this->recalculateCart($cart);
            $promotion = $this->promotionService->validateCoupon($code, $cart);
            $discount = (int) $this->promotionService->calculateDiscount($promotion, $cart);

            $cart->update([
                'coupon_code' => strtoupper(trim($code)),
                'discount_amount' => max(0, $discount),
            ]);

            return $this->recalculateCart($cart->fresh());
        });
    }

    public function removeCoupon(Cart $cart): Cart
    {
        $cart->update([
            'coupon_code' => null,
            'discount_amount' => 0,
        ]);

        return $this->recalculateCart($cart->fresh());
    }

    public function recalculateCart(Cart $cart): Cart
    {
        $cart->load('items.product.categories', 'items.variation');

        $subtotal = (int) $cart->items->sum('subtotal');
        $taxRate = (float) config('cart.tax_rate', 0);
        $taxAmount = (int) round($subtotal * $taxRate);
        $discountAmount = (int) min(max(0, $cart->discount_amount ?? 0), $subtotal);

        $cart->subtotal = $subtotal;
        $cart->tax_amount = $taxAmount;
        $cart->total = max(0, $subtotal + (int) $cart->shipping_amount + $taxAmount - $discountAmount);
        $cart->discount_amount = $discountAmount;
        $cart->save();

        return $cart->load('items.product.brand', 'items.product.categories', 'items.variation');
    }
}
