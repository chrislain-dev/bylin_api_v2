<?php

declare(strict_types=1);

namespace Modules\Cart\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cart\Http\Requests\ContributeToGiftCartRequest;
use Modules\Cart\Http\Requests\ConvertToGiftCartRequest;
use Modules\Cart\Services\GiftCartService;
use Modules\Core\Http\Controllers\ApiController;
use Throwable;

class GiftCartController extends ApiController
{
    public function __construct(
        private GiftCartService $giftCartService
    ) {}

    public function convert(ConvertToGiftCartRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $giftCart = $this->giftCartService->convertToGiftCart(
                $validated['cart_id'],
                $request->user()->id,
                $validated['message'] ?? null,
                $validated['expiration_days'] ?? null
            );
        } catch (Throwable $e) {
            return $this->errorResponse($e->getMessage(), method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 422);
        }

        return $this->successResponse([
            'gift_cart' => $giftCart,
            'share_link' => $this->giftCartService->getGiftCartLink($giftCart->gift_cart_token),
        ], 'Gift cart created successfully');
    }

    public function show(string $token): JsonResponse
    {
        $giftCart = $this->giftCartService->getByToken($token);

        return $this->successResponse($giftCart);
    }

    public function contribute(string $token, ContributeToGiftCartRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['customer_id'] = auth('sanctum')->id();

        try {
            $contributor = $this->giftCartService->addContribution(
                $token,
                $validated,
                (int) $validated['amount']
            );
        } catch (Throwable $e) {
            return $this->errorResponse($e->getMessage(), method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 422);
        }

        return $this->createdResponse(
            $contributor,
            'Contribution added. Please proceed to payment.'
        );
    }

    public function contributions(string $token): JsonResponse
    {
        $giftCart = $this->giftCartService->getByToken($token);
        $contributors = $giftCart->contributors()
            ->select([
                'id',
                'gift_cart_id',
                'contributor_name',
                'contribution_amount',
                'contribution_percentage',
                'payment_status',
                'message',
                'created_at',
            ])
            ->where('payment_status', 'completed')
            ->latest()
            ->get();

        return $this->successResponse($contributors);
    }

    public function myGiftCarts(Request $request): JsonResponse
    {
        $giftCarts = $request->user()
            ->carts()
            ->where('is_gift_cart', true)
            ->with('contributors')
            ->latest()
            ->paginate(10);

        return $this->paginatedResponse($giftCarts);
    }

    public function cancel(string $token, Request $request): JsonResponse
    {
        $giftCart = $this->giftCartService->getByToken($token);

        if ($giftCart->gift_cart_owner_id !== $request->user()->id) {
            return $this->forbiddenResponse('You are not the owner of this gift cart');
        }

        try {
            $this->giftCartService->cancelGiftCart($giftCart->id);
        } catch (Throwable $e) {
            return $this->errorResponse($e->getMessage(), method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 422);
        }

        return $this->successResponse(null, 'Gift cart cancelled');
    }
}
