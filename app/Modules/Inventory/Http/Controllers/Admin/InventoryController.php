<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Catalogue\Models\Product;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Http\Requests\AdjustStockRequest;
use Modules\Inventory\Http\Requests\BulkAdjustStockRequest;

class InventoryController extends ApiController
{
    public function __construct(
        private readonly InventoryService $inventoryService
    ) {}


    public function index(Request $request): JsonResponse
    {
        try {
            $query = Product::query()
                ->with(['brand', 'categories', 'media'])
                ->where('track_inventory', true);

            // Recherche
            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', "%{$search}%")
                        ->orWhere('sku', 'ilike', "%{$search}%");
                });
            }

            // Statut du produit (active, inactive, etc.)
            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }

            // Filtre par marque
            if ($brandId = $request->input('brand_id')) {
                $query->where('brand_id', $brandId);
            }

            // Filtre par catégorie
            if ($categoryId = $request->input('category_id')) {
                $query->whereHas('categories', function ($q) use ($categoryId) {
                    $q->where('categories.id', $categoryId);
                });
            }

            // Filtre "En stock" (stock > 0, mais pas faible ni en rupture)
            if ($request->boolean('in_stock_only')) {
                $query->where('stock_quantity', '>', 0)
                    ->whereRaw('stock_quantity > COALESCE(low_stock_threshold, 10)');
            }

            // Filtre "Stock faible"
            if ($request->boolean('low_stock_only')) {
                $query->where('stock_quantity', '>', 0)
                    ->whereRaw('stock_quantity <= COALESCE(low_stock_threshold, 10)');
            }

            // Filtre "Rupture de stock"
            if ($request->boolean('out_of_stock_only')) {
                $query->where('stock_quantity', '<=', 0);
            }

            // Pagination
            $perPage = (int) $request->input('per_page', 15);
            $products = $query->latest()->paginate($perPage);

            return $this->successResponse($products, 'Produits récupérés avec succès.');
        } catch (\Throwable $e) {
            report($e);
            return $this->errorResponse('Erreur lors de la récupération des produits.', 500);
        }
    }

    /**
     * Ajuster le stock
     */
    public function adjust(AdjustStockRequest $request): JsonResponse
    {
        try {
            $movements = $this->inventoryService->adjustStock($request->validated());

            return $this->successResponse(
                $movements,
                'Stock mis à jour avec succès.'
            );
        } catch (\DomainException | \InvalidArgumentException $e) {
            Log::warning('Stock adjustment business error', [
                'message' => $e->getMessage(),
                'data' => $request->validated()
            ]);

            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('Stock adjustment system error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $request->validated()
            ]);

            report($e);

            return $this->errorResponse(
                'Une erreur interne est survenue lors de la mise à jour du stock.',
                500
            );
        }
    }



    /**
     * Ajuster plusieurs stocks en une seule opération atomique.
     */
    public function bulkAdjust(BulkAdjustStockRequest $request): JsonResponse
    {
        try {
            $movements = $this->inventoryService->bulkAdjustStock($request->validated('adjustments'));

            return $this->successResponse([
                'count' => count($movements),
                'movements' => $movements,
            ], 'Stocks mis à jour avec succès.');
        } catch (\DomainException | \InvalidArgumentException $e) {
            Log::warning('Bulk stock adjustment business error', [
                'message' => $e->getMessage(),
                'data' => $request->validated(),
            ]);

            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('Bulk stock adjustment system error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $request->validated(),
            ]);

            report($e);

            return $this->errorResponse(
                'Une erreur interne est survenue lors de la mise à jour des stocks.',
                500
            );
        }
    }
    /**
     * Récupérer les produits en stock faible
     */
    public function lowStock(Request $request): JsonResponse
    {
        try {
            $threshold = $request->input('threshold') ? (int)$request->input('threshold') : null;
            $perPage = (int)$request->input('per_page', 15);

            $items = $this->inventoryService->getLowStockItems($threshold, $perPage);

            return $this->successResponse($items, 'Produits en stock faible récupérés.');
        } catch (\Throwable $e) {
            report($e);
            return $this->errorResponse('Erreur lors de la récupération des stocks faibles.', 500);
        }
    }

    /**
     * Récupérer les produits en rupture
     */
    public function outOfStock(Request $request): JsonResponse
    {
        try {
            $perPage = (int)$request->input('per_page', 15);

            $items = $this->inventoryService->getOutOfStockItems($perPage);

            return $this->successResponse($items, 'Produits en rupture de stock récupérés.');
        } catch (\Throwable $e) {
            report($e);
            return $this->errorResponse('Erreur lors de la récupération des ruptures.', 500);
        }
    }

    /**
     * Historique des mouvements
     */
    public function movements(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'product_id',
                'variation_id',
                'type',
                'reason',
                'date_from',
                'date_to',
                'user_id'
            ]);

            $perPage = (int)$request->input('per_page', 15);

            $movements = $this->inventoryService->getMovements($filters, $perPage);

            return $this->successResponse($movements, 'Historique des mouvements récupéré.');
        } catch (\Throwable $e) {
            report($e);
            return $this->errorResponse('Erreur lors de la récupération de l\'historique.', 500);
        }
    }

    /**
     * Statistiques globales
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = $this->inventoryService->getStatistics();

            return $this->successResponse($stats, 'Statistiques récupérées.');
        } catch (\Throwable $e) {
            report($e);
            return $this->errorResponse('Erreur lors de la récupération des statistiques.', 500);
        }
    }

    /**
     * Export des données
     */
    public function export(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'status',
                'brand_id',
                'category_id',
                'low_stock_only'
            ]);

            $format = $request->input('format', 'csv');

            $filePath = $this->inventoryService->export($filters, $format);

            return $this->successResponse([
                'file_url' => url($filePath),
                'expires_at' => now()->addHours(24)->toIso8601String()
            ], 'Export généré avec succès.');
        } catch (\Throwable $e) {
            report($e);
            return $this->errorResponse('Erreur lors de la génération de l\'export.', 500);
        }
    }
}
