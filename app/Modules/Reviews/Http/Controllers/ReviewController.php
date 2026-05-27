<?php

declare(strict_types=1);

namespace Modules\Reviews\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Order\Models\Order;
use Modules\Reviews\Http\Requests\StoreReviewRequest;
use Modules\Reviews\Http\Requests\UpdateReviewRequest;
use Modules\Reviews\Models\Review;
use Modules\Reviews\Services\ReviewService;

class ReviewController extends ApiController
{
    public function __construct(
        private readonly ReviewService $reviewService,
    ) {}

    /**
     * Get authenticated customer's reviews.
     */
    public function myReviews(): JsonResponse
    {
        $customerId = (string) auth()->id();

        $reviews = Review::with(['product', 'order'])
            ->forCustomer($customerId)
            ->latest()
            ->paginate(request()->integer('per_page', 15));

        return $this->successResponse($reviews);
    }

    /**
     * Create a new review.
     */
    public function store(StoreReviewRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $customerId = (string) auth()->id();

        $existingReview = Review::query()
            ->where('customer_id', $customerId)
            ->where('product_id', $validated['product_id'])
            ->exists();

        if ($existingReview) {
            return $this->errorResponse('Vous avez déjà laissé un avis pour ce produit.', 409);
        }

        $orderId = $validated['order_id'] ?? null;
        $isVerifiedPurchase = false;

        if ($orderId) {
            $order = Order::query()
                ->where('customer_id', $customerId)
                ->whereHas('items', fn ($query) => $query->where('product_id', $validated['product_id']))
                ->find($orderId);

            if (! $order) {
                return $this->errorResponse('Cette commande ne correspond pas au client ou au produit évalué.', 422);
            }

            $isVerifiedPurchase = true;
        }

        $review = Review::query()->create([
            'product_id' => $validated['product_id'],
            'customer_id' => $customerId,
            'order_id' => $orderId,
            'rating' => $validated['rating'],
            'title' => $validated['title'] ?? null,
            'comment' => $validated['comment'] ?? null,
            'status' => Review::STATUS_PENDING,
            'is_verified_purchase' => $isVerifiedPurchase,
        ]);

        $review->load(['product', 'order']);

        return $this->successResponse($review, 'Avis soumis avec succès. Il sera publié après validation.', 201);
    }

    /**
     * Update a pending review owned by the authenticated customer.
     */
    public function update(UpdateReviewRequest $request, string $id): JsonResponse
    {
        $customerId = (string) auth()->id();

        $review = Review::query()
            ->where('customer_id', $customerId)
            ->findOrFail($id);

        if ($review->status !== Review::STATUS_PENDING) {
            return $this->errorResponse('Vous pouvez uniquement modifier un avis encore en attente.', 403);
        }

        $review->update($request->validated());
        $review->load(['product', 'order']);

        return $this->successResponse($review, 'Avis mis à jour avec succès.');
    }

    /**
     * Delete a review owned by the authenticated customer.
     */
    public function destroy(string $id): JsonResponse
    {
        $customerId = (string) auth()->id();

        $review = Review::query()
            ->where('customer_id', $customerId)
            ->findOrFail($id);

        $this->reviewService->deleteReview($review);

        return $this->successResponse(null, 'Avis supprimé avec succès.');
    }
}
