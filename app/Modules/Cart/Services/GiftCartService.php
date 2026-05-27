<?php

declare(strict_types=1);

namespace Modules\Cart\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cart\Enums\GiftCartStatus;
use Modules\Cart\Events\GiftCartCompleted;
use Modules\Cart\Events\GiftCartContributionReceived;
use Modules\Cart\Events\GiftCartCreated;
use Modules\Cart\Events\GiftCartExpired;
use Modules\Cart\Models\Cart;
use Modules\Cart\Models\GiftCartContributor;
use Modules\Core\Exceptions\BusinessException;
use Modules\Core\Services\BaseService;

class GiftCartService extends BaseService
{
    public function convertToGiftCart(
        string $cartId,
        string $ownerId,
        ?string $message = null,
        ?int $expirationDays = 30
    ): Cart {
        return $this->transaction(function () use ($cartId, $ownerId, $message, $expirationDays) {
            $cart = Cart::query()
                ->where('customer_id', $ownerId)
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($cartId);

            if ($cart->is_gift_cart) {
                throw new BusinessException('This cart is already a gift cart');
            }

            if ($cart->items->isEmpty()) {
                throw new BusinessException('Cannot create gift cart with empty cart');
            }

            if ((int) $cart->total <= 0) {
                throw new BusinessException('Cannot create gift cart with a zero total amount');
            }

            $token = $this->generateUniqueToken();
            $expiresAt = now()->addDays(
                $expirationDays ?: (int) config('ecommerce.gift_cart.default_expiration_days', 30)
            );

            $cart->update([
                'is_gift_cart' => true,
                'gift_cart_token' => $token,
                'gift_cart_status' => GiftCartStatus::PENDING,
                'gift_cart_target_amount' => (int) $cart->total,
                'gift_cart_paid_amount' => 0,
                'gift_cart_owner_id' => $ownerId,
                'gift_cart_message' => $message,
                'gift_cart_expires_at' => $expiresAt,
            ]);

            $this->logInfo('Gift cart created', [
                'cart_id' => $cart->id,
                'owner_id' => $ownerId,
            ]);

            event(new GiftCartCreated($cart->fresh(['items.product', 'contributors'])));

            return $cart->fresh(['items.product', 'contributors']);
        });
    }

    public function getByToken(string $token): Cart
    {
        $cart = Cart::where('gift_cart_token', $token)
            ->where('is_gift_cart', true)
            ->with(['items.product', 'contributors'])
            ->firstOrFail();

        if ($cart->gift_cart_expires_at && $cart->gift_cart_expires_at->isPast()) {
            if ($cart->gift_cart_status !== GiftCartStatus::EXPIRED) {
                $cart->update(['gift_cart_status' => GiftCartStatus::EXPIRED]);
            }
        }

        return $cart->fresh(['items.product', 'contributors']);
    }

    public function addContribution(
        string $token,
        array $contributorData,
        int $amount
    ): GiftCartContributor {
        return $this->transaction(function () use ($token, $contributorData, $amount) {
            $cart = Cart::where('gift_cart_token', $token)
                ->where('is_gift_cart', true)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureCanReceiveContribution($cart);

            $remainingAmount = max(0, (int) $cart->gift_cart_target_amount - (int) $cart->gift_cart_paid_amount);

            if ($remainingAmount <= 0) {
                throw new BusinessException('This gift cart is already fully funded');
            }

            $amount = min($amount, $remainingAmount);
            $percentage = round(($amount / (int) $cart->gift_cart_target_amount) * 100, 2);

            $minPercentage = (float) config('ecommerce.gift_cart.min_contribution_percentage', 5);
            if ($percentage < $minPercentage && $amount < $remainingAmount) {
                throw new BusinessException("Minimum contribution is {$minPercentage}%");
            }

            $contributor = GiftCartContributor::create([
                'gift_cart_id' => $cart->id,
                'contributor_name' => $contributorData['name'],
                'contributor_email' => $contributorData['email'],
                'contributor_customer_id' => $contributorData['customer_id'] ?? null,
                'contribution_amount' => $amount,
                'contribution_percentage' => $percentage,
                'payment_status' => 'pending',
                'message' => $contributorData['message'] ?? null,
            ]);

            $this->logInfo('Gift cart contribution created', [
                'cart_id' => $cart->id,
                'contributor_id' => $contributor->id,
            ]);

            return $contributor->fresh(['giftCart']);
        });
    }

    public function processContributionPayment(string $contributorId, string $paymentId): void
    {
        $this->transaction(function () use ($contributorId, $paymentId) {
            $contributor = GiftCartContributor::query()->lockForUpdate()->findOrFail($contributorId);

            if ($contributor->isPaid()) {
                return;
            }

            $contributor->markAsPaid($paymentId);

            $cart = Cart::query()->lockForUpdate()->findOrFail($contributor->gift_cart_id);
            $cart->increment('gift_cart_paid_amount', (int) $contributor->contribution_amount);
            $cart->refresh();

            if ((int) $cart->gift_cart_paid_amount >= (int) $cart->gift_cart_target_amount) {
                $this->completeGiftCart($cart->id);
            } else {
                $cart->update(['gift_cart_status' => GiftCartStatus::PARTIAL]);
            }

            event(new GiftCartContributionReceived($cart->fresh(), $contributor->fresh()));

            $this->logInfo('Contribution payment processed', [
                'cart_id' => $cart->id,
                'contributor_id' => $contributorId,
            ]);
        });
    }

    protected function completeGiftCart(string $cartId): void
    {
        $cart = Cart::query()->lockForUpdate()->findOrFail($cartId);

        $cart->update(['gift_cart_status' => GiftCartStatus::COMPLETED]);

        event(new GiftCartCompleted($cart->fresh(['items.product', 'contributors'])));

        $this->logInfo('Gift cart completed', ['cart_id' => $cartId]);
    }

    public function cancelGiftCart(string $cartId, ?string $reason = null): bool
    {
        return $this->transaction(function () use ($cartId, $reason) {
            $cart = Cart::query()->lockForUpdate()->findOrFail($cartId);

            if ($cart->gift_cart_status === GiftCartStatus::COMPLETED) {
                throw new BusinessException('Cannot cancel a completed gift cart');
            }

            if ($cart->contributors()->paid()->exists()) {
                throw new BusinessException('Gift cart has paid contributions. Process refunds before cancelling.');
            }

            $cart->update(['gift_cart_status' => GiftCartStatus::CANCELLED]);

            $this->logInfo('Gift cart cancelled', [
                'cart_id' => $cartId,
                'reason' => $reason,
            ]);

            return true;
        });
    }

    public function getGiftCartLink(string $token): string
    {
        return rtrim((string) config('app.url'), '/') . "/gift-cart/{$token}";
    }

    protected function generateUniqueToken(): string
    {
        do {
            $token = 'gc_' . Str::random(32);
        } while (Cart::where('gift_cart_token', $token)->exists());

        return $token;
    }

    public function checkExpiredGiftCarts(): int
    {
        $expiredCount = 0;

        Cart::where('is_gift_cart', true)
            ->whereNotIn('gift_cart_status', [GiftCartStatus::COMPLETED, GiftCartStatus::EXPIRED])
            ->where('gift_cart_expires_at', '<', now())
            ->chunkById(100, function ($expiredCarts) use (&$expiredCount) {
                foreach ($expiredCarts as $cart) {
                    $cart->update(['gift_cart_status' => GiftCartStatus::EXPIRED]);

                    if (config('ecommerce.gift_cart.refund_on_expiration', true)) {
                        event(new GiftCartExpired($cart));
                    }

                    $expiredCount++;
                }
            });

        return $expiredCount;
    }

    private function ensureCanReceiveContribution(Cart $cart): void
    {
        if ($cart->gift_cart_expires_at && $cart->gift_cart_expires_at->isPast()) {
            $cart->update(['gift_cart_status' => GiftCartStatus::EXPIRED]);
            throw new BusinessException('This gift cart has expired');
        }

        if ($cart->gift_cart_status === GiftCartStatus::COMPLETED) {
            throw new BusinessException('This gift cart is already fully funded');
        }

        if ($cart->gift_cart_status === GiftCartStatus::EXPIRED) {
            throw new BusinessException('This gift cart has expired');
        }

        if ($cart->gift_cart_status === GiftCartStatus::CANCELLED) {
            throw new BusinessException('This gift cart has been cancelled');
        }
    }
}
