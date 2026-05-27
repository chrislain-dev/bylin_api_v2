<?php

declare(strict_types=1);

namespace Modules\Reviews\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Catalogue\Models\Product;
use Modules\Core\Services\BaseService;
use Modules\Reviews\Models\Review;
use Modules\Reviews\Models\ReviewMedia;

class ReviewService extends BaseService
{
    /**
     * Get reviews with filters and pagination
     */
    public function getReviews(array $filters = []): array
    {
        $query = Review::query()
            ->with(['customer', 'product', 'media'])
            ->withCount('media');

        // Filter by status
        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        // Filter by rating
        if (isset($filters['rating'])) {
            $query->where('rating', $filters['rating']);
        }

        // Filter by verified purchase
        if (isset($filters['verified_only']) && $filters['verified_only']) {
            $query->where('is_verified_purchase', true);
        }

        // Filter by product
        if (isset($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        // Filter by customer
        if (isset($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        // Search in title and comment
        if (isset($filters['search']) && !empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('comment', 'like', "%{$search}%");
            });
        }

        // Handle trashed reviews
        if (isset($filters['with_trashed']) && $filters['with_trashed']) {
            $query->withTrashed();
        } elseif (isset($filters['only_trashed']) && $filters['only_trashed']) {
            $query->onlyTrashed();
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';

        $allowedSorts = ['created_at', 'rating', 'helpful_count', 'status'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDirection);
        }

        // Pagination
        $perPage = min($filters['per_page'] ?? 15, 100);

        return [
            'data' => $query->paginate($perPage),
            'filters' => $filters,
        ];
    }

    /**
     * Get a single review with details
     */
    public function getReview(string $id): ?Review
    {
        return Review::with(['customer', 'product', 'order', 'media'])
            ->withTrashed()
            ->find($id);
    }

    /**
     * Approve a review
     */
    public function approveReview(Review $review): Review
    {
        try {
            DB::beginTransaction();

            $review->update(['status' => Review::STATUS_APPROVED]);

            // Update product average rating
            $this->updateProductRating($review->product_id);

            DB::commit();

            Log::info('Review approved', [
                'review_id' => $review->id,
                'product_id' => $review->product_id
            ]);

            return $review->fresh(['customer', 'product', 'media']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error approving review', [
                'review_id' => $review->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Reject a review
     */
    public function rejectReview(Review $review, ?string $reason = null): Review
    {
        try {
            DB::beginTransaction();

            $review->update(['status' => Review::STATUS_REJECTED]);

            // Update product average rating (in case it was previously approved)
            $this->updateProductRating($review->product_id);

            DB::commit();

            Log::info('Review rejected', [
                'review_id' => $review->id,
                'has_reason' => filled($reason)
            ]);

            return $review->fresh(['customer', 'product', 'media']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error rejecting review', [
                'review_id' => $review->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete a review
     */
    public function deleteReview(Review $review): bool
    {
        try {
            DB::beginTransaction();

            $productId = $review->product_id;

            $review->delete();

            // Update product rating after deletion
            $this->updateProductRating($productId);

            DB::commit();

            Log::info('Review deleted', ['review_id' => $review->id]);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting review', [
                'review_id' => $review->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Restore a soft-deleted review
     */
    public function restoreReview(Review $review): Review
    {
        try {
            DB::beginTransaction();

            $review->restore();

            // Update product rating if review is approved
            if ($review->status === Review::STATUS_APPROVED) {
                $this->updateProductRating($review->product_id);
            }

            DB::commit();

            Log::info('Review restored', ['review_id' => $review->id]);

            return $review->fresh(['customer', 'product', 'media']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error restoring review', [
                'review_id' => $review->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Bulk approve reviews
     */
    public function bulkApprove(array $reviewIds): int
    {
        try {
            DB::beginTransaction();

            $reviews = Review::whereIn('id', $reviewIds)->get();
            $productIds = [];

            foreach ($reviews as $review) {
                $review->update(['status' => Review::STATUS_APPROVED]);
                $productIds[] = $review->product_id;
            }

            // Update ratings for affected products
            foreach (array_unique($productIds) as $productId) {
                $this->updateProductRating($productId);
            }

            DB::commit();

            Log::info('Bulk approve reviews', [
                'count' => count($reviewIds),
                'review_ids' => $reviewIds
            ]);

            return count($reviewIds);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error bulk approving reviews', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Bulk reject reviews
     */
    public function bulkReject(array $reviewIds): int
    {
        try {
            DB::beginTransaction();

            $reviews = Review::whereIn('id', $reviewIds)->get();
            $productIds = [];

            foreach ($reviews as $review) {
                $review->update(['status' => Review::STATUS_REJECTED]);
                $productIds[] = $review->product_id;
            }

            // Update ratings for affected products
            foreach (array_unique($productIds) as $productId) {
                $this->updateProductRating($productId);
            }

            DB::commit();

            Log::info('Bulk reject reviews', [
                'count' => count($reviewIds),
                'review_ids' => $reviewIds
            ]);

            return count($reviewIds);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error bulk rejecting reviews', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Bulk delete reviews
     */
    public function bulkDelete(array $reviewIds): int
    {
        try {
            DB::beginTransaction();

            $reviews = Review::whereIn('id', $reviewIds)->get();
            $productIds = [];

            foreach ($reviews as $review) {
                $productIds[] = $review->product_id;
                $review->delete();
            }

            // Update ratings for affected products
            foreach (array_unique($productIds) as $productId) {
                $this->updateProductRating($productId);
            }

            DB::commit();

            Log::info('Bulk delete reviews', [
                'count' => count($reviewIds),
                'review_ids' => $reviewIds
            ]);

            return count($reviewIds);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error bulk deleting reviews', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Bulk restore soft-deleted reviews
     */
    public function bulkRestore(array $reviewIds): int
    {
        try {
            DB::beginTransaction();

            $reviews = Review::onlyTrashed()->whereIn('id', $reviewIds)->get();
            $productIds = [];

            foreach ($reviews as $review) {
                $review->restore();

                if ($review->status === Review::STATUS_APPROVED) {
                    $productIds[] = $review->product_id;
                }
            }

            foreach (array_unique($productIds) as $productId) {
                $this->updateProductRating($productId);
            }

            DB::commit();

            Log::info('Bulk restore reviews', [
                'count' => $reviews->count(),
                'review_ids' => $reviewIds
            ]);

            return $reviews->count();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error bulk restoring reviews', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get review statistics
     */
    public function getStatistics(): array
    {
        return [
            'total' => Review::count(),
            'pending' => Review::where('status', Review::STATUS_PENDING)->count(),
            'approved' => Review::where('status', Review::STATUS_APPROVED)->count(),
            'rejected' => Review::where('status', Review::STATUS_REJECTED)->count(),
            'verified_purchases' => Review::where('is_verified_purchase', true)->count(),
            'with_media' => Review::has('media')->count(),
            'by_rating' => Review::select('rating', DB::raw('count(*) as count'))
                ->groupBy('rating')
                ->orderBy('rating', 'desc')
                ->pluck('count', 'rating')
                ->toArray(),
            'average_rating' => round(Review::where('status', Review::STATUS_APPROVED)->avg('rating'), 2),
            'recent_reviews' => Review::with(['customer', 'product'])
                ->latest()
                ->limit(5)
                ->get(),
        ];
    }

    /**
     * Recalculate and update product rating
     */
    public function updateProductRating(string $productId): void
    {
        try {
            $product = Product::find($productId);

            if (!$product) {
                Log::warning('Product not found for rating update', ['product_id' => $productId]);
                return;
            }

            $stats = Review::where('product_id', $productId)
                ->where('status', Review::STATUS_APPROVED)
                ->selectRaw('AVG(rating) as average, COUNT(*) as count')
                ->first();

            $average = (float) ($stats->average ?? 0);
            $count   = (int) ($stats->count ?? 0);

            $product->update([
                'rating_average' => round($average, 2),
                'rating_count' => $count,
            ]);

            Log::info('Product rating updated', [
                'product_id' => $productId,
                'average' => $product->rating_average,
                'count' => $product->rating_count
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating product rating', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get reviews for a specific product
     */
    public function getProductReviews(string $productId, array $filters = []): array
    {
        $filters['product_id'] = $productId;
        return $this->getReviews($filters);
    }

    /**
     * Get reviews by a specific customer
     */
    public function getCustomerReviews(string $customerId, array $filters = []): array
    {
        $filters['customer_id'] = $customerId;
        return $this->getReviews($filters);
    }
}
