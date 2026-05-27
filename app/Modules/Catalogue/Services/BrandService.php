<?php

declare(strict_types=1);

namespace Modules\Catalogue\Services;

use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Modules\Catalogue\Models\Brand;
use Modules\Core\Services\BaseService;
use Illuminate\Support\Facades\Storage;

class BrandService extends BaseService
{

    public function createBrand(array $data): Brand
    {
        return $this->transaction(function () use ($data) {

            $data['slug'] = $this->generateUniqueSlug($data['name']);

            if (isset($data['logo']) && $data['logo'] instanceof UploadedFile) {
                $logoPath = $data['logo']->store('brands/logos', 'public');
                $data['logo'] = $logoPath;
            }

            $brand = Brand::create($data);

            $this->logInfo('Marque créée', ['brand_id' => $brand->id]);

            return $brand;
        });
    }

    public function updateBrand(string|Brand $id, array $data): Brand
    {
        $brand = $id instanceof Brand ? $id : Brand::findOrFail($id);

        return $this->transaction(function () use ($brand, $data) {

            $brand->update(collect($data)->except(['logo', 'remove_logo'])->toArray());

            if (!empty($data['remove_logo'])) {
                $brand->clearMediaCollection('logo');
            }

            if (isset($data['logo']) && $data['logo'] instanceof UploadedFile) {

                $brand->clearMediaCollection('logo');

                $brand->addMedia($data['logo'])
                    ->toMediaCollection('logo');
            }

            return $brand->refresh();
        });
    }

    public function deleteBrand(string $id): bool
    {
        return $this->transaction(function () use ($id) {
            $brand = Brand::findOrFail($id);

            $brand->delete();

            $this->logInfo('Brand deleted', ['brand_id' => $id]);

            return true;
        });
    }


    public function forceDeleteBrand(string $id): bool
    {
        return $this->transaction(function () use ($id) {
            $brand = Brand::withTrashed()->withCount('products')->findOrFail($id);

            if ($brand->products_count > 0) {
                throw new \InvalidArgumentException(
                    'Impossible de supprimer définitivement une marque liée à des produits.'
                );
            }

            $brand->clearMediaCollection('logo');
            $brand->forceDelete();

            $this->logInfo('Marque supprimée définitivement', ['brand_id' => $id]);

            return true;
        });
    }

    private function generateUniqueSlug(string $name, ?string $ignoreId = null): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $counter = 1;

        while (Brand::where('slug', $slug)
            ->when($ignoreId, fn($query) => $query->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    public function deleteBrandLogo(Brand $brand): void
    {
        if ($brand->logo && Storage::disk('public')->exists($brand->logo)) {
            Storage::disk('public')->delete($brand->logo);
            $this->logInfo('Logo deleted from disk', [
                'brand_id' => $brand->id,
                'logo' => $brand->logo
            ]);
        }
    }
}
