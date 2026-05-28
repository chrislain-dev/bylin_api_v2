<?php

declare(strict_types=1);

namespace Modules\Reviews\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Reviews\Http\Requests\BulkReviewIdsRequest;
use Modules\Reviews\Http\Requests\RejectReviewRequest;
use Illuminate\Support\Facades\Log;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Reviews\Models\Review;
use Modules\Reviews\Services\ReviewService;

class ReviewController extends ApiController
{
    public function __construct(
        protected ReviewService $reviewService
    ) {}

    /**
     * List reviews with filters
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'search' => $request->input('search'),
                'status' => $request->input('status', 'all'),
                'rating' => $request->input('rating'),
                'verified_only' => $request->boolean('verified_only'),
                'product_id' => $request->input('product_id'),
                'customer_id' => $request->input('customer_id'),
                'with_trashed' => $request->boolean('with_trashed'),
                'only_trashed' => $request->boolean('only_trashed'),
                'per_page' => $request->input('per_page', 15),
                'sort_by' => $request->input('sort_by', 'created_at'),
                'sort_direction' => $request->input('sort_direction', 'desc'),
            ];

            $result = $this->reviewService->getReviews($filters);

            return $this->successResponse($result['data']);
        } catch (\Exception $e) {
            Log::error('Error fetching reviews', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse(
                'Erreur lors du chargement des avis',
                500
            );
        }
    }

    /**
     * Show a single review
     */
    public function show(string $id): JsonResponse
    {
        try {
            $review = $this->reviewService->getReview($id);

            if (!$review) {
                return $this->errorResponse('Avis introuvable', 404);
            }

            return $this->successResponse($review);
        } catch (\Exception $e) {
            Log::error('Error showing review', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Erreur lors du chargement de l\'avis', 500);
        }
    }

    /**
     * Approve a review
     */
    public function approve(string $id): JsonResponse
    {
        try {
            $review = Review::findOrFail($id);

            if ($review->status === Review::STATUS_APPROVED) {
                return $this->errorResponse('Cet avis est déjà approuvé', 422);
            }

            $approvedReview = $this->reviewService->approveReview($review);

            return $this->successResponse(
                $approvedReview,
                'Avis approuvé avec succès'
            );
        } catch (\Exception $e) {
            Log::error('Error approving review', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Erreur lors de l\'approbation de l\'avis',
                500
            );
        }
    }

    /**
     * Reject a review
     */
    public function reject(string $id, RejectReviewRequest $request): JsonResponse
    {
        try {
            $review = Review::findOrFail($id);

            if ($review->status === Review::STATUS_REJECTED) {
                return $this->errorResponse('Cet avis est déjà rejeté', 422);
            }

            $reason = $request->validated('reason');
            $rejectedReview = $this->reviewService->rejectReview($review, $reason);

            return $this->successResponse(
                $rejectedReview,
                'Avis rejeté avec succès'
            );
        } catch (\Exception $e) {
            Log::error('Error rejecting review', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Erreur lors du rejet de l\'avis',
                500
            );
        }
    }

    /**
     * Delete a review
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $review = Review::findOrFail($id);

            $this->reviewService->deleteReview($review);

            return $this->successResponse(null, 'Avis supprimé avec succès');
        } catch (\Exception $e) {
            Log::error('Error deleting review', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Erreur lors de la suppression de l\'avis',
                500
            );
        }
    }

    /**
     * Restore a soft-deleted review
     */
    public function restore(string $id): JsonResponse
    {
        try {
            $review = Review::onlyTrashed()->findOrFail($id);

            $restoredReview = $this->reviewService->restoreReview($review);

            return $this->successResponse(
                $restoredReview,
                'Avis restauré avec succès'
            );
        } catch (\Exception $e) {
            Log::error('Error restoring review', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Erreur lors de la restauration de l\'avis',
                500
            );
        }
    }

    /**
     * Bulk restore soft-deleted reviews
     */
    public function bulkRestore(BulkReviewIdsRequest $request): JsonResponse
    {
        try {
            $count = $this->reviewService->bulkRestore($request->validated('ids'));

            return $this->successResponse(
                null,
                "{$count} avis restauré(s) avec succès"
            );
        } catch (\Exception $e) {
            Log::error('Error bulk restoring reviews', [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Erreur lors de la restauration multiple',
                500
            );
        }
    }

    /**
     * Bulk approve reviews
     */
    public function bulkApprove(BulkReviewIdsRequest $request): JsonResponse
    {
        try {
            $count = $this->reviewService->bulkApprove($request->validated('ids'));

            return $this->successResponse(
                null,
                "{$count} avis approuvé(s) avec succès"
            );
        } catch (\Exception $e) {
            Log::error('Error bulk approving reviews', [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Erreur lors de l\'approbation multiple',
                500
            );
        }
    }

    /**
     * Bulk reject reviews
     */
    public function bulkReject(BulkReviewIdsRequest $request): JsonResponse
    {
        try {
            $count = $this->reviewService->bulkReject($request->validated('ids'));

            return $this->successResponse(
                null,
                "{$count} avis rejeté(s) avec succès"
            );
        } catch (\Exception $e) {
            Log::error('Error bulk rejecting reviews', [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Erreur lors du rejet multiple',
                500
            );
        }
    }

    /**
     * Bulk delete reviews
     */
    public function bulkDestroy(BulkReviewIdsRequest $request): JsonResponse
    {
        try {
            $count = $this->reviewService->bulkDelete($request->validated('ids'));

            return $this->successResponse(
                null,
                "{$count} avis supprimé(s) avec succès"
            );
        } catch (\Exception $e) {
            Log::error('Error bulk deleting reviews', [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Erreur lors de la suppression multiple',
                500
            );
        }
    }

    /**
     * Get review statistics
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = $this->reviewService->getStatistics();

            return $this->successResponse($stats);
        } catch (\Exception $e) {
            Log::error('Error fetching review statistics', [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Erreur lors du chargement des statistiques',
                500
            );
        }
    }
}
