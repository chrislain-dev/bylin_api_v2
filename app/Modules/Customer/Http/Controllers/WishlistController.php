<?php

declare(strict_types=1);

namespace Modules\Customer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Customer\Http\Requests\AddToWishlistRequest;
use Modules\Customer\Models\Wishlist;

class WishlistController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $wishlist = Wishlist::with(['product.brand', 'product.categories'])
            ->forCustomer($request->user()->id)
            ->latest()
            ->get();

        return $this->successResponse($wishlist);
    }

    public function add(AddToWishlistRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $customerId = $request->user()->id;

        if (Wishlist::hasProduct($customerId, $validated['product_id'])) {
            return $this->errorResponse('Product already in wishlist', 409);
        }

        $wishlistItem = Wishlist::addProduct(
            $customerId,
            $validated['product_id'],
            $validated['notes'] ?? null
        );

        return $this->successResponse(
            $wishlistItem->load('product.brand', 'product.categories'),
            'Product added to wishlist',
            201
        );
    }

    public function remove(string $productId, Request $request): JsonResponse
    {
        $deleted = Wishlist::removeProduct($request->user()->id, $productId);

        if (! $deleted) {
            return $this->errorResponse('Product not found in wishlist', 404);
        }

        return $this->successResponse(null, 'Product removed from wishlist');
    }

    public function check(string $productId, Request $request): JsonResponse
    {
        return $this->successResponse([
            'in_wishlist' => Wishlist::hasProduct($request->user()->id, $productId),
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        $count = Wishlist::forCustomer($request->user()->id)->delete();

        return $this->successResponse(['count' => $count], 'Wishlist cleared');
    }
}
