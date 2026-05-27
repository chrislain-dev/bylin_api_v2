<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalogue\Services\ProductAuthenticityService;
use Modules\Catalogue\Http\Requests\GenerateAuthenticityCodesRequest;
use Modules\Catalogue\Http\Requests\MarkAsFakeRequest;
use Modules\Core\Http\Controllers\ApiController;

/**
 * Authenticity Controller
 * 
 * Handles QR code verification and authenticity management
 */
class AuthenticityController extends ApiController
{
    public function __construct(
        private ProductAuthenticityService $authenticityService
    ) {}

    /**
     * Verify QR code (public endpoint - no auth required)
     */
    public function verify(string $qrCode, Request $request): JsonResponse
    {
        $scanData = [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'customer_id' => auth('sanctum')->id(),
        ];

        if ($request->has('location')) {
            $scanData['location'] = $request->input('location');
        }

        $result = $this->authenticityService->verifyQRCode($qrCode, $scanData);

        return response()->json($result);
    }

    /**
     * Generate authenticity codes (admin only)
     */
    public function generate(GenerateAuthenticityCodesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $codes = $this->authenticityService->generateAuthenticityCode(
            $validated['product_id'],
            $validated['quantity'],
            $validated['serial_prefix'] ?? null
        );

        return $this->successResponse(
            $codes,
            "Generated {$validated['quantity']} authenticity codes"
        );
    }

    /**
     * Get product authenticity statistics (admin only)
     */
    public function productStats(string $productId): JsonResponse
    {
        $stats = $this->authenticityService->getProductStats($productId);
        return $this->successResponse($stats);
    }

    /**
     * Get scan analytics (admin only)
     */
    public function analytics(Request $request): JsonResponse
    {
        $filters = $request->only(['from_date', 'to_date']);
        $analytics = $this->authenticityService->getScanAnalytics($filters);
        
        return $this->successResponse($analytics);
    }

    /**
     * Mark code as fake (admin only)
     */
    public function markAsFake(string $qrCode, MarkAsFakeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $this->authenticityService->markAsFake($qrCode, $validated['reason']);

        return $this->successResponse(null, 'Code marked as fake');
    }
}
