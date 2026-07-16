<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalogue\Models\Collection;
use Modules\Catalogue\Models\Product;
use Modules\Core\Http\Controllers\ApiController;

class CollectionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 12);
        $perPage = min(max($perPage, 1), 48);

        $query = Collection::query()
            ->current()
            ->withActiveProductsCount()
            ->whereHas('products.brand', fn ($brand) => $brand->where('is_bylin_brand', true))
            ->when($request->boolean('featured'), fn ($q) => $q->featured())
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderedByRelease();

        return $this->paginatedResponse($query->paginate($perPage), 'Collections Bylin récupérées avec succès');
    }

    public function show(string $slug): JsonResponse
    {
        $collection = Collection::query()
            ->current()
            ->withActiveProductsCount()
            ->where('slug', $slug)
            ->whereHas('products.brand', fn ($brand) => $brand->where('is_bylin_brand', true))
            ->firstOrFail();

        return $this->successResponse($collection, 'Collection Bylin récupérée avec succès');
    }

    public function products(Request $request, string $slug): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 24);
        $perPage = min(max($perPage, 1), 48);

        $collection = Collection::query()
            ->current()
            ->where('slug', $slug)
            ->whereHas('products.brand', fn ($brand) => $brand->where('is_bylin_brand', true))
            ->firstOrFail();

        $products = Product::query()
            ->with(['brand', 'categories', 'media', 'variations'])
            ->active()
            ->where('collection_id', $collection->id)
            ->whereHas('brand', fn ($brand) => $brand->where('is_bylin_brand', true))
            ->latest()
            ->paginate($perPage);

        return $this->paginatedResponse($products, 'Produits de la collection récupérés avec succès');
    }
}
