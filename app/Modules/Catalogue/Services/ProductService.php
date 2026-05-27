<?php

declare(strict_types=1);

namespace Modules\Catalogue\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Modules\Catalogue\Enums\ProductStatus;
use Modules\Catalogue\Models\Product;
use Modules\Core\Services\BaseService;
use Modules\Inventory\Models\StockMovement;
use Modules\Catalogue\Models\ProductVariation;
use Modules\Catalogue\Models\ProductAuthenticityCode;

class ProductService extends BaseService
{

    private const BYLIN_BRAND_SLUG = 'bylin';

    public function __construct(
        private ?ProductAuthenticityService $authenticityService = null
    ) {}

    public function createProduct(array $data): Product
    {
        return $this->transaction(function () use ($data) {

            $categories = $data['categories'] ?? [];
            $variations = $data['variations'] ?? [];
            $images = $data['images'] ?? [];
            unset($data['categories'], $data['variations'], $data['images']);

            $product = Product::create($data);

            if (!empty($categories)) $product->categories()->attach($categories);

            if (!empty($variations) && $product->is_variable) {
                $this->createVariations($product, $variations);
            }

            if (!empty($images)) {
                foreach ($images as $image) {
                    $product->addMedia($image)->toMediaCollection('images');
                }
            }

            if ($this->shouldGenerateAuthenticityCode($product, $data)) {
                $codesCount = (int) ($data['authenticity_codes_count'] ?? 10);

                $this->authenticityService->generateAuthenticityCode(
                    $product->id,
                    $codesCount
                );

                $this->logInfo('Authenticity codes generated for new product', [
                    'product_id' => $product->id,
                    'brand' => $product->brand->name ?? 'Unknown',
                    'codes_count' => $codesCount
                ]);
            }

            $this->logInfo('Product created', ['product_id' => $product->id]);

            return $product->fresh(['brand', 'categories', 'variations', 'media']);
        });
    }

    public function updateProduct(string $id, array $data): Product
    {
        return $this->transaction(function () use ($id, $data) {
            $product = Product::findOrFail($id);

            // Capturer l'état AVANT la mise à jour
            $wasAuthenticationRequired = $product->requires_authenticity;

            $variationsData = $data['variations'] ?? null;
            $categories = $data['categories'] ?? null;
            $imagesToDelete = $data['images_to_delete'] ?? [];
            $newImages = $data['images'] ?? [];
            $requestedCodesCount = (int) ($data['authenticity_codes_count'] ?? 0);

            unset($data['categories'], $data['variations'], $data['images'], $data['images_to_delete']);

            // Mise à jour du produit
            $product->update($data);

            if ($categories !== null) {
                $product->categories()->sync($categories);
            }

            if ($product->is_variable && $variationsData !== null) {
                $this->syncVariations($product, $variationsData);
            }

            if (!empty($imagesToDelete)) {
                $product->media()->whereIn('id', $imagesToDelete)->each->delete();
            }

            if (!empty($newImages)) {
                foreach ($newImages as $image) {
                    $product->addMedia($image)->toMediaCollection('images');
                }
            }

            // ✅ Gestion des codes d'authenticité APRÈS la mise à jour
            if ($this->shouldGenerateAuthenticityCode($product, $data)) {
                $this->handleAuthenticityCodesUpdate(
                    $product,
                    $requestedCodesCount,
                    $wasAuthenticationRequired
                );
            } elseif ($wasAuthenticationRequired && !$product->requires_authenticity) {
                // Désactivation de l'authentification
                $this->logInfo('Authenticity requirement disabled', [
                    'product_id' => $product->id,
                    'note' => 'Existing codes kept for historical purposes'
                ]);
            }

            $this->logInfo('Product updated', ['product_id' => $product->id]);

            return $product->fresh(['brand', 'categories', 'variations', 'media']);
        });
    }

    private function shouldGenerateAuthenticityCode(Product $product, array $data): bool
    {
        // Vérifier si l'authentification est requise
        $requiresAuth = $data['requires_authenticity'] ?? $product->requires_authenticity ?? false;

        if (!$requiresAuth) {
            return false;
        }

        // Vérifier si c'est la marque Bylin
        $isBylinBrand = $this->isBylinProduct($product);

        if (!$isBylinBrand) {
            $this->logWarning('Authenticity codes requested for non-Bylin product', [
                'product_id' => $product->id,
                'brand_id' => $product->brand_id,
                'brand_name' => $product->brand->name ?? 'Unknown'
            ]);

            return false;
        }

        return $requiresAuth && $isBylinBrand && $this->authenticityService !== null;
    }

    private function isBylinProduct(Product $product): bool
    {
        $product->loadMissing('brand');

        if (!$product->brand) {
            return false;
        }

        // Vérifier par slug (plus flexible que l'ID qui peut changer entre environnements)
        return strtolower($product->brand->slug) === self::BYLIN_BRAND_SLUG;
    }

    private function handleAuthenticityCodesUpdate(
        Product $product,
        int $requestedCount,
        bool $wasAuthenticationRequired
    ): void {
        if (!$this->authenticityService) {
            return;
        }

        // Récupérer les statistiques des codes existants
        $stats = $this->getCodeStatistics($product->id);

        $existingTotal = $stats['total'];
        $activatedCount = $stats['activated'];
        $unactivatedCount = $stats['unactivated'];

        // Cas 1: Activation de l'authentification (aucun code existant)
        if (!$wasAuthenticationRequired && $existingTotal === 0) {
            $this->authenticityService->generateAuthenticityCode(
                $product->id,
                $requestedCount
            );

            $this->logInfo('Authenticity codes generated on activation', [
                'product_id' => $product->id,
                'codes_generated' => $requestedCount
            ]);

            return;
        }

        // Cas 2: Ajout de codes supplémentaires
        if ($requestedCount > $existingTotal) {
            $additionalCodes = $requestedCount - $existingTotal;

            $this->authenticityService->generateAuthenticityCode(
                $product->id,
                $additionalCodes
            );

            $this->logInfo('Additional authenticity codes generated', [
                'product_id' => $product->id,
                'existing_total' => $existingTotal,
                'existing_activated' => $activatedCount,
                'existing_unactivated' => $unactivatedCount,
                'additional_codes' => $additionalCodes,
                'new_total' => $existingTotal + $additionalCodes
            ]);

            return;
        }

        // Cas 3: Réduction du nombre de codes demandé
        if ($requestedCount < $existingTotal) {

            // On ne peut PAS supprimer des codes déjà activés
            if ($requestedCount < $activatedCount) {
                $this->logWarning('Cannot reduce codes below activated count', [
                    'product_id' => $product->id,
                    'requested_count' => $requestedCount,
                    'existing_total' => $existingTotal,
                    'activated_count' => $activatedCount,
                    'action' => 'Keeping all existing codes'
                ]);

                return;
            }

            // Calculer combien de codes non activés supprimer
            $codesToDelete = $existingTotal - $requestedCount;

            if ($codesToDelete <= $unactivatedCount) {
                // Supprimer uniquement les codes non activés
                $this->deleteUnactivatedCodes($product->id, $codesToDelete);

                $this->logInfo('Unactivated authenticity codes removed', [
                    'product_id' => $product->id,
                    'codes_deleted' => $codesToDelete,
                    'remaining_total' => $existingTotal - $codesToDelete,
                    'remaining_activated' => $activatedCount
                ]);

                return;
            }

            // Si on arrive ici, c'est qu'il n'y a pas assez de codes non activés
            $this->logWarning('Cannot reduce codes: not enough unactivated codes', [
                'product_id' => $product->id,
                'requested_count' => $requestedCount,
                'unactivated_count' => $unactivatedCount,
                'action' => 'Keeping existing codes'
            ]);

            return;
        }

        // Cas 4: Aucun changement nécessaire
        $this->logInfo('Authenticity codes unchanged', [
            'product_id' => $product->id,
            'total_codes' => $existingTotal,
            'activated_codes' => $activatedCount,
            'unactivated_codes' => $unactivatedCount
        ]);
    }

    protected function createVariations(Product $product, array $variationsData): void
    {
        foreach ($variationsData as $variationData) {
            // ✅ FIX: Convertir stock_quantity en entier
            $stockQuantity = (int) ($variationData['stock_quantity'] ?? 0);

            $product->variations()->create([
                'variation_name' => $variationData['variation_name'],
                'price' => (float) $variationData['price'], // ✅ Aussi convertir price en float
                'compare_price' => isset($variationData['compare_price']) ? (float) $variationData['compare_price'] : null,
                'cost_price' => isset($variationData['cost_price']) ? (float) $variationData['cost_price'] : null,
                'stock_quantity' => $stockQuantity,
                'stock_status' => $this->determineStockStatus($stockQuantity),
                'is_active' => (bool) ($variationData['is_active'] ?? true),
                'attributes' => $variationData['attributes'] ?? [],
                'barcode' => $variationData['barcode'] ?? null,
                'sku' => $variationData['sku'] ?? $this->generateVariationSku($product, $variationData),
            ]);
        }
    }

    protected function syncVariations(Product $product, array $variationsData): void
    {
        $this->logInfo('Syncing variations', [
            'product_id' => $product->id,
            'variations_count' => count($variationsData),
        ]);

        $existingVariationIds = $product->variations()->pluck('id')->toArray();
        $processedIds = [];

        foreach ($variationsData as $index => $variationData) {
            $variationId = $variationData['id'] ?? null;

            // ✅ FIX: Convertir stock_quantity en entier
            $stockQuantity = (int) ($variationData['stock_quantity'] ?? 0);

            if ($variationId && in_array($variationId, $existingVariationIds)) {

                $variation = ProductVariation::find($variationId);

                if ($variation) {
                    $updateData = [
                        'variation_name' => $variationData['variation_name'],
                        'price' => (float) $variationData['price'],
                        'compare_price' => isset($variationData['compare_price']) ? (float) $variationData['compare_price'] : null,
                        'cost_price' => isset($variationData['cost_price']) ? (float) $variationData['cost_price'] : null,
                        'stock_quantity' => $stockQuantity,
                        'stock_status' => $this->determineStockStatus($stockQuantity),
                        'is_active' => (bool) ($variationData['is_active'] ?? true),
                        'attributes' => $variationData['attributes'] ?? [],
                        'barcode' => $variationData['barcode'] ?? null,
                    ];

                    if (!empty($variationData['sku']) && $variationData['sku'] !== $variation->sku) {
                        $updateData['sku'] = $variationData['sku'];
                    }

                    $variation->update($updateData);

                    $processedIds[] = $variationId;

                    $this->logInfo('Variation updated', [
                        'variation_id' => $variationId,
                        'variation_name' => $updateData['variation_name'],
                    ]);
                }
            } else {

                $newVariation = $product->variations()->create([
                    'variation_name' => $variationData['variation_name'],
                    'price' => (float) $variationData['price'],
                    'compare_price' => isset($variationData['compare_price']) ? (float) $variationData['compare_price'] : null,
                    'cost_price' => isset($variationData['cost_price']) ? (float) $variationData['cost_price'] : null,
                    'stock_quantity' => $stockQuantity,
                    'stock_status' => $this->determineStockStatus($stockQuantity),
                    'is_active' => (bool) ($variationData['is_active'] ?? true),
                    'attributes' => $variationData['attributes'] ?? [],
                    'barcode' => $variationData['barcode'] ?? null,
                    'sku' => $variationData['sku'] ?? $this->generateVariationSku($product, $variationData),
                ]);

                $processedIds[] = $newVariation->id;

                $this->logInfo('Variation created', [
                    'variation_id' => $newVariation->id,
                    'sku' => $newVariation->sku,
                ]);
            }
        }

        $idsToDelete = array_diff($existingVariationIds, $processedIds);

        if (!empty($idsToDelete)) {
            $this->logInfo('Deleting variations', [
                'ids_to_delete' => $idsToDelete,
            ]);

            ProductVariation::whereIn('id', $idsToDelete)->delete();
        }

        $this->logInfo('Variations sync completed', [
            'product_id' => $product->id,
            'processed_count' => count($processedIds),
            'deleted_count' => count($idsToDelete),
        ]);
    }

    protected function generateVariationSku(Product $product, array $variationData): string
    {
        $baseSku = $product->sku;
        $suffix = Str::upper(Str::random(4));

        if (!empty($variationData['attributes'])) {
            $attrString = implode('-', array_values($variationData['attributes']));
            $suffix = Str::slug($attrString) . '-' . $suffix;
        }

        $sku = "{$baseSku}-{$suffix}";

        $count = 1;
        $originalSku = $sku;

        while (ProductVariation::where('sku', $sku)->exists()) {
            $sku = "{$originalSku}-{$count}";
            $count++;
        }

        return $sku;
    }

    private function getCodeStatistics(string $productId): array
    {
        $total = ProductAuthenticityCode::where('product_id', $productId)->count();
        $activated = ProductAuthenticityCode::where('product_id', $productId)
            ->activated()
            ->count();

        return [
            'total' => $total,
            'activated' => $activated,
            'unactivated' => $total - $activated,
        ];
    }

    private function deleteUnactivatedCodes(string $productId, int $count): int
    {
        $codesToDelete = ProductAuthenticityCode::where('product_id', $productId)
            ->where('is_activated', false)
            ->orderBy('created_at', 'desc')
            ->limit($count)
            ->get();

        $deletedCount = 0;

        foreach ($codesToDelete as $code) {
            $code->delete();
            $deletedCount++;
        }

        return $deletedCount;
    }

    public function deleteProduct(string $id): bool
    {
        return $this->transaction(function () use ($id) {
            $product = Product::findOrFail($id);
            $product->delete();

            $this->logInfo('Product deleted', ['product_id' => $id]);

            return true;
        });
    }

    public function duplicateProduct(string $id): Product
    {
        return $this->transaction(function () use ($id) {
            $original = Product::with(['categories', 'variations', 'media'])->findOrFail($id);

            $data = $original->toArray();

            $data['name'] = $data['name'] . ' (Copie)';
            $data['is_featured'] = false;
            $data['status'] = ProductStatus::DRAFT;

            // Nettoyage des champs auto-générés et timestamps
            unset(
                $data['id'],
                $data['slug'],     
                $data['sku'],       
                $data['barcode'],  
                $data['created_at'],
                $data['updated_at'],
                $data['deleted_at'],
                $data['media']     
            );

            // Créer le produit dupliqué
            $duplicate = Product::create($data);

            // Attacher les catégories
            $duplicate->categories()->attach($original->categories->pluck('id'));

            // Duplication des variations si elles existent
            if ($original->is_variable && $original->variations->isNotEmpty()) {
                foreach ($original->variations as $variation) {
                    $varData = $variation->toArray();

                    // Nettoyage des IDs et relations
                    unset(
                        $varData['id'],
                        $varData['product_id'],
                        $varData['created_at'],
                        $varData['updated_at']
                    );

                    // Le SKU et barcode de la variation seront générés automatiquement
                    // si vides dans ProductService::createVariations
                    $varData['sku'] = null;
                    $varData['barcode'] = null;

                    $duplicate->variations()->create($varData);
                }
            }

            // Duplication des images
            foreach ($original->media as $media) {
                $media->copy($duplicate, 'images');
            }

            $this->logInfo('Product duplicated', [
                'original_id' => $id,
                'duplicate_id' => $duplicate->id,
                'variations_count' => $duplicate->variations->count()
            ]);

            return $duplicate->fresh(['brand', 'categories', 'variations', 'media']);
        });
    }

    public function bulkUpdate(array $productIds, string $action): int
    {
        return $this->transaction(function () use ($productIds, $action) {
            $query = Product::whereIn('id', $productIds);

            $count = match ($action) {
                'activate' => $query->update(['status' => 'active']),
                'deactivate' => $query->update(['status' => 'inactive']),
                'delete' => $query->delete(),
                'feature' => $query->update(['is_featured' => true]),
                'unfeature' => $query->update(['is_featured' => false]),
                default => 0,
            };

            $this->logInfo('Bulk product update', [
                'action' => $action,
                'count' => $count,
            ]);

            return $count;
        });
    }

    public function exportProducts(array $filters = []): string
    {
        $directory = 'exports';
        $filename = 'products-' . now()->format('Y-m-d-His') . '.csv';
        $relativePath = $directory . '/' . $filename;

        Storage::disk('local')->makeDirectory($directory);

        $absolutePath = Storage::disk('local')->path($relativePath);
        $handle = fopen($absolutePath, 'w');

        if ($handle === false) {
            throw new \RuntimeException('Impossible de créer le fichier d’export des produits.');
        }

        fputcsv($handle, [
            'id',
            'name',
            'sku',
            'brand',
            'status',
            'price',
            'compare_price',
            'cost_price',
            'stock_quantity',
            'low_stock_threshold',
            'track_inventory',
            'is_featured',
            'is_preorder_enabled',
            'requires_authenticity',
            'created_at',
            'updated_at',
        ]);

        $query = Product::query()
            ->with('brand')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['brand_id'] ?? null, fn ($query, $brandId) => $query->where('brand_id', $brandId))
            ->when($filters['category_id'] ?? null, function ($query, $categoryId) {
                $query->whereHas('categories', fn ($categoryQuery) => $categoryQuery->where('categories.id', $categoryId));
            })
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->search($search))
            ->orderBy('created_at', 'desc');

        $query->chunkById(500, function ($products) use ($handle) {
            foreach ($products as $product) {
                fputcsv($handle, [
                    $product->id,
                    $product->name,
                    $product->sku,
                    $product->brand?->name,
                    $product->status instanceof ProductStatus ? $product->status->value : (string) $product->status,
                    $product->price,
                    $product->compare_price,
                    $product->cost_price,
                    $product->stock_quantity,
                    $product->low_stock_threshold,
                    $product->track_inventory ? 1 : 0,
                    $product->is_featured ? 1 : 0,
                    $product->is_preorder_enabled ? 1 : 0,
                    $product->requires_authenticity ? 1 : 0,
                    optional($product->created_at)->toDateTimeString(),
                    optional($product->updated_at)->toDateTimeString(),
                ]);
            }
        });

        fclose($handle);

        $this->logInfo('Products exported', [
            'filters' => $filters,
            'path' => $relativePath,
        ]);

        return $relativePath;
    }

    private function determineStockStatus(int $quantity): string
    {
        if ($quantity <= 0) {
            return 'out_of_stock';
        }

        return 'in_stock';
    }

    public function updateStock(
        string $productId,
        int $quantity,
        string $operation = 'set',
        ?string $reason = null,
        ?string $notes = null
    ): array {
        try {
            $product = Product::findOrFail($productId);

            if ($product->is_variable) {
                return [
                    'success' => false,
                    'message' => 'Impossible de mettre à jour directement le stock des produits variables. Veuillez plutôt mettre à jour les variantes.'
                ];
            }

            if (!$product->track_inventory) {
                return [
                    'success' => false,
                    'message' => 'Ce produit ne permet pas de suivre les stocks.'
                ];
            }

            $currentStock = $product->stock_quantity;

            $finalQuantity = match ($operation) {
                'set' => $quantity,
                'add' => $currentStock + $quantity,
                'sub' => $currentStock - $quantity,
                default => $currentStock
            };

            if ($finalQuantity < 0) {
                return [
                    'success' => false,
                    'message' => 'La quantité en stock ne peut pas être négative.'
                ];
            }

            $movementType = $this->determineMovementType($operation, $reason);
            $movementReason = $this->mapReasonToStockMovementReason($reason);

            $movement = StockMovement::createMovement([
                'product_id' => $productId,
                'variation_id' => null,
                'type' => $movementType,
                'reason' => $movementReason,
                'quantity' => $finalQuantity - $currentStock,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);

            return [
                'success' => true,
                'product' => $product->fresh(),
                'movement' => $movement
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function updateVariationStock(
        string $productId,
        string $variationId,
        int $quantity,
        string $operation = 'set',
        ?string $reason = null,
        ?string $notes = null
    ): array {
        try {
            Product::findOrFail($productId);
            $variation = ProductVariation::where('product_id', $productId)->where('id', $variationId)->firstOrFail();

            $currentStock = $variation->stock_quantity;

            $finalQuantity = match ($operation) {
                'set' => $quantity,
                'add' => $currentStock + $quantity,
                'sub' => $currentStock - $quantity,
                default => $currentStock
            };

            if ($finalQuantity < 0) {
                return [
                    'success' => false,
                    'message' => 'Stock quantity cannot be negative'
                ];
            }

            $movementType = $this->determineMovementType($operation, $reason);
            $movementReason = $this->mapReasonToStockMovementReason($reason);

            $movement = StockMovement::createMovement([
                'product_id' => $productId,
                'variation_id' => $variationId,
                'type' => $movementType,
                'reason' => $movementReason,
                'quantity' => $finalQuantity - $currentStock,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);

            return [
                'success' => true,
                'variation' => $variation->fresh(),
                'movement' => $movement
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function getStockHistory(string $productId, int $perPage = 15)
    {
        return StockMovement::with(['creator', 'variation'])->forProduct($productId)->latest()->paginate($perPage);
    }

    public function reserveStock(string $productId, ?string $variationId, int $quantity, ?string $orderId = null): bool
    {
        try {
            if ($variationId) {
                StockMovement::recordSale(
                    productId: $productId,
                    quantity: $quantity,
                    variationId: $variationId,
                    orderId: $orderId
                );
            } else {
                StockMovement::recordSale(
                    productId: $productId,
                    quantity: $quantity,
                    variationId: null,
                    orderId: $orderId
                );
            }

            return true;
        } catch (\Exception $e) {
            $this->logError('Failed to reserve stock', [
                'product_id' => $productId,
                'variation_id' => $variationId,
                'quantity' => $quantity,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    public function releaseStock(string $productId, ?string $variationId, int $quantity, ?string $orderId = null): bool
    {
        try {
            if ($variationId) {
                StockMovement::recordReturn(
                    productId: $productId,
                    quantity: $quantity,
                    variationId: $variationId,
                    orderId: $orderId
                );
            } else {
                StockMovement::recordReturn(
                    productId: $productId,
                    quantity: $quantity,
                    variationId: null,
                    orderId: $orderId
                );
            }

            return true;
        } catch (\Exception $e) {
            $this->logError('Failed to release stock', [
                'product_id' => $productId,
                'variation_id' => $variationId,
                'quantity' => $quantity,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    private function determineMovementType(string $operation, ?string $reason): string
    {
        if ($operation === 'set') return StockMovement::TYPE_ADJUSTMENT;
        if ($reason === 'purchase' || $reason === 'return') return StockMovement::TYPE_IN;
        if ($reason === 'sale' || $reason === 'damage' || $reason === 'theft') return StockMovement::TYPE_OUT;

        return match ($operation) {
            'add' => StockMovement::TYPE_IN,
            'sub' => StockMovement::TYPE_OUT,
            default => StockMovement::TYPE_ADJUSTMENT
        };
    }

    private function mapReasonToStockMovementReason(?string $reason): string
    {
        if (!$reason) return StockMovement::REASON_ADJUSTMENT;

        return match ($reason) {
            'sale' => StockMovement::REASON_SALE,
            'theft' => StockMovement::REASON_LOST,
            'return' => StockMovement::REASON_RETURN,
            'damage' => StockMovement::REASON_DAMAGED,
            'purchase' => StockMovement::REASON_PURCHASE,
            'transfer' => StockMovement::REASON_ADJUSTMENT,
            'adjustment' => StockMovement::REASON_ADJUSTMENT,
            'initial_stock' => StockMovement::REASON_INITIAL_STOCK,
            default => StockMovement::REASON_ADJUSTMENT
        };
    }
}
