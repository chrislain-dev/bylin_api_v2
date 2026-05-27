<?php

declare(strict_types=1);

namespace Modules\Cart\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Cart\Http\Requests\AddToCartRequest;
use Modules\Cart\Http\Requests\ApplyCouponRequest;
use Modules\Cart\Http\Requests\UpdateCartItemRequest;
use Modules\Cart\Services\CartService;
use Modules\Core\Http\Controllers\ApiController;
use Throwable;

class CartController extends ApiController
{
    public function __construct(
        protected CartService $cartService
    ) {}

    public function show(Request $request): JsonResponse
    {
        [$customerId, $sessionId, $generatedSession] = $this->resolveCartOwner($request);

        $cart = $this->cartService->getCart($customerId, $sessionId);

        $response = $this->successResponse($cart);

        if ($generatedSession) {
            $response->headers->set('X-Session-ID', $sessionId);
        }

        return $response;
    }

    public function addItem(AddToCartRequest $request): JsonResponse
    {
        [$customerId, $sessionId, $generatedSession] = $this->resolveCartOwner($request);
        $cart = $this->cartService->getCart($customerId, $sessionId);

        try {
            $cart = $this->cartService->addItem($cart, $request->validated());
        } catch (Throwable $e) {
            return $this->errorResponse($e->getMessage(), method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 422);
        }

        $response = $this->successResponse($cart, 'Item added to cart');

        if ($generatedSession) {
            $response->headers->set('X-Session-ID', $sessionId);
        }

        return $response;
    }

    public function updateItem(string $itemId, UpdateCartItemRequest $request): JsonResponse
    {
        [$customerId, $sessionId] = $this->resolveCartOwner($request);
        $cart = $this->cartService->getCart($customerId, $sessionId);

        if (! $cart->items()->where('id', $itemId)->exists()) {
            return $this->errorResponse('Item not found in cart', 404);
        }

        try {
            $cart = $this->cartService->updateItem($cart, $itemId, (int) $request->validated('quantity'));
        } catch (Throwable $e) {
            return $this->errorResponse($e->getMessage(), method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 422);
        }

        return $this->successResponse($cart, 'Cart updated');
    }

    public function removeItem(string $itemId, Request $request): JsonResponse
    {
        [$customerId, $sessionId] = $this->resolveCartOwner($request);
        $cart = $this->cartService->getCart($customerId, $sessionId);

        if (! $cart->items()->where('id', $itemId)->exists()) {
            return $this->errorResponse('Item not found in cart', 404);
        }

        $cart = $this->cartService->removeItem($cart, $itemId);

        return $this->successResponse($cart, 'Item removed from cart');
    }

    public function clear(Request $request): JsonResponse
    {
        [$customerId, $sessionId] = $this->resolveCartOwner($request);
        $cart = $this->cartService->getCart($customerId, $sessionId);

        $this->cartService->clearCart($cart);

        return $this->successResponse(null, 'Cart cleared');
    }

    public function applyCoupon(ApplyCouponRequest $request): JsonResponse
    {
        [$customerId, $sessionId] = $this->resolveCartOwner($request);
        $cart = $this->cartService->getCart($customerId, $sessionId);

        try {
            $cart = $this->cartService->applyCoupon($cart, $request->validated('coupon_code'));
        } catch (Throwable $e) {
            return $this->errorResponse($e->getMessage(), method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 422);
        }

        return $this->successResponse($cart, 'Coupon applied');
    }

    public function removeCoupon(Request $request): JsonResponse
    {
        [$customerId, $sessionId] = $this->resolveCartOwner($request);
        $cart = $this->cartService->getCart($customerId, $sessionId);
        $cart = $this->cartService->removeCoupon($cart);

        return $this->successResponse($cart, 'Coupon removed');
    }

    private function resolveCartOwner(Request $request): array
    {
        $customerId = $request->user()?->id;
        $sessionId = $request->header('X-Session-ID');
        $generatedSession = false;

        if (! $customerId && ! $sessionId) {
            $sessionId = (string) Str::uuid();
            $generatedSession = true;
        }

        return [$customerId, $sessionId, $generatedSession];
    }
}
