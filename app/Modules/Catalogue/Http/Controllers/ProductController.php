<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Catalogue\Models\Product;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Modules\Catalogue\Services\PreorderService;
use Modules\Core\Http\Controllers\ApiController;

class ProductController extends ApiController
{
    public function __construct(
        private PreorderService $preorderService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $products = QueryBuilder::for(Product::class)
            ->allowedFilters([
                'name',
                'status',
                'is_featured',
                AllowedFilter::scope('price_between'),
                AllowedFilter::scope('in_category', 'inCategory'),
                AllowedFilter::scope('category_id', 'inCategory'), // Uses scope for pivot table
                AllowedFilter::exact('brand_id'),
                AllowedFilter::scope('color', 'withColor'),
                AllowedFilter::scope('size', 'withSize'),
                AllowedFilter::exact('is_new'),
                AllowedFilter::exact('is_on_sale'),
                AllowedFilter::exact('is_featured'),
            ])
            ->allowedSorts(['name', 'price', 'created_at', 'rating_average'])
            ->with(['brand', 'categories', 'media', 'variations'])
            ->where('status', 'active')
            ->paginate($request->input('per_page', 15));

        return $this->paginatedResponse($products);
    }

    public function show(string $id): JsonResponse
    {
        // Check if input is a valid UUID format
        $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
        
        $query = Product::with([
            'brand',
            'categories',
            'media',
            'variations' => fn($q) => $q->active(),
            'attributes.values'
        ]);
        
        // Search by UUID if valid, otherwise by slug
        $product = $isUuid 
            ? $query->where('id', $id)->firstOrFail()
            : $query->where('slug', $id)->firstOrFail();

        $product->increment('views_count');

        return $this->successResponse($product);
    }

    public function preorderInfo(string $id): JsonResponse
    {
        $info = $this->preorderService->getPreorderInfo($id);
        return $this->successResponse($info);
    }
}
