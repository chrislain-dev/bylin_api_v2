<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Catalogue\Models\Brand;
use Modules\Catalogue\Services\BrandService;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Catalogue\Http\Requests\StoreBrandRequest;
use Modules\Catalogue\Http\Requests\UpdateBrandRequest;
use Modules\Catalogue\Http\Requests\BulkBrandIdsRequest;

class BrandController extends ApiController
{
    public function __construct(
        private BrandService $brandService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Brand::query()
            ->with(['media'])
            ->withCount('products');

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', (bool) $request->is_active);
        }

        if ($request->boolean('only_trashed')) {
            $query->onlyTrashed();
        } elseif ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        $query->orderBy('sort_order')->orderBy('name');

        $brands = $query->paginate($request->per_page ?? 15);

        return $this->successResponse($brands);
    }

    public function store(StoreBrandRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $brand = $this->brandService->createBrand($validated);

        return $this->createdResponse($brand, 'Marque créée avec succès');
    }

    public function show(string $id): JsonResponse
    {
        $brand = Brand::query()
            ->with(['media'])
            ->withTrashed()
            ->findOrFail($id);

        return $this->successResponse($brand);
    }

    public function update(UpdateBrandRequest $request, Brand $brand): JsonResponse
    {
        $validated = $request->validated();

        $updated_brand = $this->brandService->updateBrand($brand->id, $validated);

        return $this->successResponse($updated_brand, 'Marque mise à jour avec succès');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->brandService->deleteBrand($id);

        return $this->successResponse(null, 'Marque supprimée avec succès');
    }

    public function restore(string $id): JsonResponse
    {
        $brand = Brand::onlyTrashed()->findOrFail($id);

        $brand->restore();

        return $this->successResponse(
            $brand->load('media'),
            'Marque restaurée avec succès'
        );
    }

    public function forceDelete(string $id): JsonResponse
    {
        $this->brandService->forceDeleteBrand($id);

        return $this->successResponse(null, 'Marque supprimée définitivement');
    }

    public function bulkDestroy(BulkBrandIdsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $brands = Brand::whereIn('id', $validated['ids'])->get();
        foreach ($brands as $brand) {
            $brand->clearMediaCollection('logo');
        }

        Brand::whereIn('id', $validated['ids'])->delete();

        return $this->successResponse(
            null,
            count($validated['ids']) . ' marque(s) supprimée(s) avec succès'
        );
    }

    public function bulkRestore(BulkBrandIdsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $count = Brand::onlyTrashed()
            ->whereIn('id', $validated['ids'])
            ->restore();

        return $this->successResponse(
            null,
            $count . ' marque(s) restaurée(s) avec succès'
        );
    }

    public function bulkForceDelete(BulkBrandIdsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $brands = Brand::withTrashed()
            ->whereIn('id', $validated['ids'])
            ->get();

        foreach ($brands as $brand) {
            $brand->clearMediaCollection('logo');
            $brand->forceDelete();
        }

        return $this->successResponse(
            null,
            count($validated['ids']) . ' marque(s) supprimée(s) définitivement'
        );
    }

    public function statistics(): JsonResponse
    {
        $stats = [
            'total' => Brand::withTrashed()->count(),
            'active' => Brand::where('is_active', true)->count(),
            'inactive' => Brand::where('is_active', false)->count(),
            'with_products' => Brand::has('products')->count(),
            'trashed' => Brand::onlyTrashed()->count(),
        ];

        return $this->successResponse($stats);
    }
}
