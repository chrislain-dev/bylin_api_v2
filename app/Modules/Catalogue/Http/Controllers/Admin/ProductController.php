<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Controllers\Admin;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Catalogue\Models\Product;
use Modules\Catalogue\Services\ProductService;
use Modules\Catalogue\Services\PreorderService;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Catalogue\Models\ProductAuthenticityCode;
use Modules\Catalogue\Http\Requests\UpdateStockRequest;
use Modules\Catalogue\Http\Requests\StoreProductRequest;
use Modules\Catalogue\Http\Requests\UpdateProductRequest;
use Modules\Catalogue\Http\Requests\EnablePreorderRequest;
use Modules\Catalogue\Http\Requests\BulkDestroyProductsRequest;
use Modules\Catalogue\Http\Requests\BulkUpdateProductsRequest;

class ProductController extends ApiController
{
    public function __construct(
        private ProductService $productService,
        private PreorderService $preorderService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Product::query()->with(['brand', 'categories', 'variations', 'media'])->withCount('variations');

        if ($request->filled('in_stock')) $query->inStock();
        if ($request->filled('is_featured')) $query->featured();
        if ($request->filled('is_preorder')) $query->preorder();
        if ($request->filled('search')) $query->search($request->search);
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('category_id')) $query->inCategory($request->category_id);
        if ($request->filled('brand_id')) $query->where('brand_id', $request->brand_id);
        if ($request->filled('collection_id')) $query->where('collection_id', $request->collection_id);
        if ($request->filled('min_price') && $request->filled('max_price')) $query->priceBetween($request->min_price, $request->max_price);

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        $query->orderBy($sortBy, $sortOrder);

        $perPage = min($request->get('per_page', 15), 100);
        $products = $query->paginate($perPage);

        return $this->successResponse(
            $products,
            'Produits récupérés avec succès'
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->createProduct($request->validated());

        return $this->createdResponse(
            $product,
            'Product created successfully'
        );
    }

    public function show(string $id): JsonResponse
    {
        $product = Product::with([
            'brand',
            'categories',
            'variations',
            'attributes',
            'media'
        ])->findOrFail($id);

        return $this->successResponse(
            $product,
            'Product retrieved successfully'
        );
    }

    public function update(string $id, UpdateProductRequest $request): JsonResponse
    {
        $product = $this->productService->updateProduct($id, $request->validated());

        return $this->successResponse(
            $product,
            'Product updated successfully'
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $this->productService->deleteProduct($id);

        return $this->successResponse(
            null,
            'Product deleted successfully'
        );
    }

    public function restore(string $id): JsonResponse
    {
        $product = Product::withTrashed()->findOrFail($id);
        $product->restore();

        return $this->successResponse(
            $product,
            'Product restored successfully'
        );
    }

    public function forceDelete(string $id): JsonResponse
    {
        $product = Product::withTrashed()->findOrFail($id);
        $product->forceDelete();

        return $this->successResponse(
            null,
            'Product permanently deleted'
        );
    }

    public function updateStock(string $id, UpdateStockRequest $request): JsonResponse
    {
        $result = $this->productService->updateStock(
            productId: $id,
            quantity: $request->input('quantity'),
            operation: $request->input('operation', 'set'),
            reason: $request->input('reason'),
            notes: $request->input('notes')
        );

        if ($result['success']) {
            return $this->successResponse(
                $result['product'],
                'Stock mis à jour avec succès'
            );
        }

        return $this->errorResponse($result['message'], 400);
    }

    public function updateVariationStock(
        string $productId,
        string $variationId,
        UpdateStockRequest $request
    ): JsonResponse {
        $result = $this->productService->updateVariationStock(
            productId: $productId,
            variationId: $variationId,
            quantity: $request->input('quantity'),
            operation: $request->input('operation', 'set'),
            reason: $request->input('reason'),
            notes: $request->input('notes')
        );

        if ($result['success']) {
            return $this->successResponse(
                $result['variation'],
                'Variation stock updated successfully'
            );
        }

        return $this->errorResponse($result['message'], 400);
    }

    public function stockHistory(string $id, Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 15);

        $history = $this->productService->getStockHistory($id, $perPage);

        return $this->successResponse(
            $history,
            'Stock history retrieved successfully'
        );
    }

    public function enablePreorder(string $id, EnablePreorderRequest $request): JsonResponse
    {
        $product = $this->preorderService->enablePreorder(
            productId: $id,
            availableDate: isset($request['available_date'])
                ? Carbon::parse($request['available_date'])
                : null,
            limit: $request['limit'] ?? null,
            message: $request['message'] ?? null,
            terms: $request['terms'] ?? null
        );

        return $this->successResponse(
            $product,
            'Preorder enabled successfully'
        );
    }

    public function disablePreorder(string $id): JsonResponse
    {
        $product = $this->preorderService->disablePreorder($id, 'manual');

        return $this->successResponse(
            $product,
            'Preorder disabled successfully'
        );
    }

    public function preorderInfo(string $id): JsonResponse
    {
        $info = $this->preorderService->getPreorderInfo($id);

        return $this->successResponse(
            $info,
            'Preorder info retrieved successfully'
        );
    }

    public function duplicate(string $id): JsonResponse
    {
        $product = $this->productService->duplicateProduct($id);

        return $this->createdResponse(
            $product,
            'Product duplicated successfully'
        );
    }

    public function bulkUpdate(BulkUpdateProductsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if ($validated['action'] === 'delete' && ! $request->user()?->can('catalogue.delete')) {
            return $this->errorResponse('Vous n’êtes pas autorisé à supprimer des produits.', 403);
        }

        $count = $this->productService->bulkUpdate(
            $validated['product_ids'],
            $validated['action']
        );

        return $this->successResponse(
            ['updated_count' => $count],
            "{$count} produit(s) mis à jour avec succès"
        );
    }

    /**
     * Suppression en masse de produits (soft delete)
     * 
     * Permet de supprimer plusieurs produits à la fois.
     * Les produits sont placés en corbeille et peuvent être restaurés.
     */
    public function bulkDestroy(BulkDestroyProductsRequest $request): JsonResponse
    {
        try {
            $count = Product::whereIn('id', $request['ids'])->delete();

            return $this->successResponse(
                ['deleted_count' => $count],
                "{$count} produit(s) supprimé(s) avec succès"
            );
        } catch (\Exception $e) {
            Log::error('Échec de la suppression en masse', [
                'ids' => $request['ids'],
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Échec de la suppression des produits : ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Restauration en masse de produits
     */
    public function bulkRestore(BulkDestroyProductsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $count = Product::withTrashed()
                ->whereIn('id', $validated['ids'])
                ->restore();

            return $this->successResponse(
                ['restored_count' => $count],
                "{$count} produit(s) restauré(s) avec succès"
            );
        } catch (\Exception $e) {
            Log::error('Échec de la restauration en masse', [
                'ids' => $validated['ids'],
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Échec de la restauration : ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Suppression définitive en masse
     */
    public function bulkForceDelete(BulkDestroyProductsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $count = Product::withTrashed()
                ->whereIn('id', $validated['ids'])
                ->forceDelete();

            return $this->successResponse(
                ['deleted_count' => $count],
                "{$count} produit(s) supprimé(s) définitivement"
            );
        } catch (\Exception $e) {
            Log::error('Échec de la suppression définitive', [
                'ids' => $validated['ids'],
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Échec de la suppression définitive : ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Obtenir les statistiques des codes d'authenticité d'un produit
     */
    public function authenticityStats(string $id): JsonResponse
    {
        try {
            $product = Product::findOrFail($id);

            // Vérifier que c'est un produit Bylin avec authenticité
            if (!$product->requires_authenticity) {
                return $this->errorResponse(
                    'Ce produit ne nécessite pas d\'authenticité',
                    400
                );
            }

            $total = ProductAuthenticityCode::where('product_id', $id)->count();
            $activated = ProductAuthenticityCode::where('product_id', $id)
                ->where('is_activated', true)
                ->count();

            $stats = [
                'total' => $total,
                'activated' => $activated,
                'unactivated' => $total - $activated,
            ];

            return $this->successResponse(
                $stats,
                'Statistiques récupérées avec succès'
            );
        } catch (\Exception $e) {
            Log::error('Erreur récupération stats authenticité', [
                'product_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Erreur lors de la récupération des statistiques',
                500
            );
        }
    }

    public function export(Request $request): JsonResponse
    {
        $filters = $request->only([
            'status',
            'brand_id',
            'category_id',
            'search'
        ]);

        $filePath = $this->productService->exportProducts($filters);

        return $this->successResponse(
            ['download_url' => $filePath],
            'Export completed successfully'
        );
    }

    public function statistics(): JsonResponse
    {
        $stats = [
            'total_products' => Product::count(),
            'active_products' => Product::where('status', 'active')->count(),
            'out_of_stock' => Product::where('stock_quantity', 0)->count(),
            'low_stock' => Product::where('stock_quantity', '>', 0)
                ->whereRaw('stock_quantity <= low_stock_threshold')
                ->count(),
            'preorder_products' => Product::where('is_preorder_enabled', true)->count(),
            'featured_products' => Product::where('is_featured', true)->count(),
            'total_value' => Product::sum(DB::raw('price * stock_quantity')),
        ];

        return $this->successResponse($stats);
    }
}
