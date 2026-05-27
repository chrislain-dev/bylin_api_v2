<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Model;
use Modules\Catalogue\Models\Product;
use Modules\Catalogue\Models\ProductVariation;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Enums\StockOperation;
use Modules\Inventory\Enums\StockReason;
use Modules\Order\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;

class InventoryService
{

    public function adjustStock(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $product = Product::withCount('variations')
                ->lockForUpdate()
                ->findOrFail($data['product_id']);

            $results = [];
            $reason = StockReason::from($data['reason']);

            // PRODUIT VARIABLE
            if ($product->variations_count > 0) {
                if (empty($data['variations'])) {
                    throw new \InvalidArgumentException(
                        'Le produit possède des variations. Vous devez fournir le tableau "variations".'
                    );
                }

                foreach ($data['variations'] as $variationData) {
                    $variation = ProductVariation::lockForUpdate()
                        ->findOrFail($variationData['id']);

                    // Sécurité : vérifier l'appartenance
                    if ($variation->product_id !== $product->id) {
                        throw new \InvalidArgumentException(
                            "La variation {$variation->id} n'appartient pas au produit {$product->id}"
                        );
                    }

                    $results[] = $this->performAdjustment(
                        target: $variation,
                        parentProduct: $product,
                        quantity: (int) $variationData['quantity'],
                        operation: StockOperation::from($variationData['operation']),
                        reason: $reason,
                        notes: $data['notes'] ?? null
                    );
                }

                // Recalcul du stock total du parent
                $totalStock = $product->variations()->sum('stock_quantity');
                $product->update(['stock_quantity' => $totalStock]);

                if (method_exists($product, 'updateStockStatus')) {
                    $product->updateStockStatus();
                }
            }
            // PRODUIT SIMPLE
            else {
                if (!isset($data['quantity']) || !isset($data['operation'])) {
                    throw new \InvalidArgumentException(
                        'Les champs "quantity" et "operation" sont requis pour un produit simple.'
                    );
                }

                $results[] = $this->performAdjustment(
                    target: $product,
                    parentProduct: $product,
                    quantity: (int) $data['quantity'],
                    operation: StockOperation::from($data['operation']),
                    reason: $reason,
                    notes: $data['notes'] ?? null
                );
            }

            Log::info('InventoryService::adjustStock SUCCESS', [
                'movements_created' => count($results),
                'product_id' => $product->id
            ]);

            return $results;
        });
    }



    /**
     * Ajuste plusieurs produits/variations dans une transaction unique.
     * Si une ligne échoue, aucun stock n'est modifié.
     *
     * @param array<int, array<string, mixed>> $adjustments
     * @return array<int, StockMovement>
     */
    public function bulkAdjustStock(array $adjustments): array
    {
        return DB::transaction(function () use ($adjustments) {
            $movements = [];

            foreach ($adjustments as $adjustment) {
                if (!empty($adjustment['variation_id'])) {
                    $variation = ProductVariation::query()
                        ->with('product')
                        ->lockForUpdate()
                        ->findOrFail($adjustment['variation_id']);

                    $product = $variation->product;

                    if (!$product) {
                        throw new \InvalidArgumentException('La variation sélectionnée n\'est rattachée à aucun produit.');
                    }

                    $movements[] = $this->performAdjustment(
                        target: $variation,
                        parentProduct: $product,
                        quantity: (int) $adjustment['quantity'],
                        operation: StockOperation::from($adjustment['operation']),
                        reason: StockReason::from($adjustment['reason']),
                        notes: $adjustment['notes'] ?? null
                    );

                    $product->update([
                        'stock_quantity' => $product->variations()->sum('stock_quantity'),
                    ]);

                    if (method_exists($product, 'updateStockStatus')) {
                        $product->updateStockStatus();
                    }

                    continue;
                }

                $product = Product::query()
                    ->lockForUpdate()
                    ->findOrFail($adjustment['product_id']);

                if ($product->variations()->exists()) {
                    throw new \InvalidArgumentException(
                        "Le produit {$product->id} possède des variations. Ajustez ses variations directement."
                    );
                }

                $movements[] = $this->performAdjustment(
                    target: $product,
                    parentProduct: $product,
                    quantity: (int) $adjustment['quantity'],
                    operation: StockOperation::from($adjustment['operation']),
                    reason: StockReason::from($adjustment['reason']),
                    notes: $adjustment['notes'] ?? null
                );
            }

            return $movements;
        });
    }
    private function performAdjustment(
        Model $target,
        Product $parentProduct,
        int $quantity,
        StockOperation $operation,
        StockReason $reason,
        ?string $notes,
        ?string $referenceId = null,
        ?string $referenceType = null
    ): StockMovement {
        $currentStock = (int) $target->stock_quantity;

        // Calcul du nouveau stock
        $newStock = match ($operation) {
            StockOperation::SET => $quantity,
            StockOperation::ADD => $currentStock + $quantity,
            StockOperation::SUB => $currentStock - $quantity,
        };

        // Validation métier : stock négatif
        if ($newStock < 0) {
            throw new \DomainException(
                "Stock insuffisant pour '{$target->sku}'. " .
                    "Stock actuel: {$currentStock}, " .
                    "opération: {$operation->value} {$quantity}, " .
                    "résultat: {$newStock}"
            );
        }

        // Validation métier : quantité > 0 pour add/sub
        if (in_array($operation, [StockOperation::ADD, StockOperation::SUB]) && $quantity === 0) {
            throw new \DomainException(
                "La quantité doit être supérieure à 0 pour une opération d'ajout ou de retrait."
            );
        }

        $delta = $newStock - $currentStock;

        // Création du mouvement
        $movement = StockMovement::create([
            'product_id'      => $parentProduct->id,
            'variation_id'    => $target instanceof ProductVariation ? $target->id : null,
            'type'            => $this->getMovementType($delta),
            'reason'          => $reason->value,
            'quantity'        => $delta,
            'quantity_before' => $currentStock,
            'quantity_after'  => $newStock,
            'reference_id'    => $referenceId,
            'reference_type'  => $referenceType,
            'notes'           => $notes,
            'created_by'      => Auth::id(),
        ]);

        // Mise à jour du stock
        $target->update(['stock_quantity' => $newStock]);

        // Mise à jour du statut
        if (method_exists($target, 'updateStockStatus')) {
            $target->updateStockStatus();
        }

        Log::debug('Stock adjusted', [
            'type' => get_class($target),
            'id' => $target->id,
            'sku' => $target->sku,
            'before' => $currentStock,
            'after' => $newStock,
            'delta' => $delta,
            'operation' => $operation->value
        ]);

        return $movement;
    }

    private function getMovementType(int $delta): string
    {
        return match (true) {
            $delta > 0 => 'in',
            $delta < 0 => 'out',
            default => 'adjustment',
        };
    }

    // --- MÉTHODES DE LECTURE ---

    public function getMovements(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = StockMovement::query()
            ->with(['product', 'variation', 'creator'])
            ->latest('created_at');

        if (!empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (!empty($filters['variation_id'])) {
            $query->where('variation_id', $filters['variation_id']);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['reason'])) {
            $query->where('reason', $filters['reason']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('created_by', $filters['user_id']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Version standard avec plusieurs requêtes (plus lisible)
     */
    public function getStatistics(): array
    {
        // ========================================================================
        // COMPTEURS DE BASE
        // ========================================================================

        // Total de produits suivis (incluant les variables)
        $totalProducts = Product::where('track_inventory', true)->count();

        // Total de variations (pour info)
        $totalVariations = ProductVariation::whereHas('product', function ($q) {
            $q->where('track_inventory', true);
        })->count();

        // ========================================================================
        // STATUTS DE STOCK - CORRIGÉ
        // ========================================================================

        // ✅ CORRECTION : COUNT au lieu de SUM pour compter les produits
        // Produits en rupture (stock = 0)
        $outOfStockCount = Product::where('track_inventory', true)
            ->where('stock_quantity', '=', 0)
            ->count(); // ✅ COUNT

        // Produits en stock faible (0 < stock <= low_stock_threshold)
        // ✅ CORRECTION : Utiliser le seuil personnalisé de chaque produit
        $lowStockCount = Product::where('track_inventory', true)
            ->where('stock_quantity', '>', 0)
            ->whereRaw('stock_quantity <= COALESCE(low_stock_threshold, 10)')
            ->count(); // ✅ COUNT

        // ✅ CORRECTION : Produits en stock (pas en rupture, pas en stock faible)
        $totalItemsInStock = Product::where('track_inventory', true)
            ->where('stock_quantity', '>', 0)
            ->whereRaw('stock_quantity > COALESCE(low_stock_threshold, 10)')
            ->count(); // ✅ COUNT au lieu de SUM
        // ========================================================================
        // VALEUR DU STOCK (ici on garde SUM car on veut la valeur totale)
        // ========================================================================

        // Valeur totale du stock (products uniquement, les variations sont déjà agrégées)
        $totalStockValue = Product::where('track_inventory', true)
            ->selectRaw('SUM(stock_quantity * COALESCE(cost_price, price, 0)) as total_value')
            ->value('total_value') ?? 0;

        // ✅ CORRECTION : Convertir en float avant round (PostgreSQL retourne une string)
        $totalStockValue = round((float) $totalStockValue, 2);

        // ========================================================================
        // MOUVEMENTS - COMPTEURS GLOBAUX
        // ========================================================================

        $movementsToday = StockMovement::whereDate('created_at', today())->count();

        $movementsThisWeek = StockMovement::whereBetween('created_at', [
            now()->startOfWeek(),
            now()->endOfWeek()
        ])->count();

        $movementsThisMonth = StockMovement::whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        // ========================================================================
        // MOUVEMENTS - ENTRÉES VS SORTIES
        // ========================================================================

        // Aujourd'hui
        $stockInToday = StockMovement::whereDate('created_at', today())
            ->where('type', StockMovement::TYPE_IN)
            ->sum('quantity');

        $stockOutToday = StockMovement::whereDate('created_at', today())
            ->where('type', StockMovement::TYPE_OUT)
            ->sum('quantity');

        // Cette semaine
        $stockInThisWeek = StockMovement::whereBetween('created_at', [
            now()->startOfWeek(),
            now()->endOfWeek()
        ])
            ->where('type', StockMovement::TYPE_IN)
            ->sum('quantity');

        $stockOutThisWeek = StockMovement::whereBetween('created_at', [
            now()->startOfWeek(),
            now()->endOfWeek()
        ])
            ->where('type', StockMovement::TYPE_OUT)
            ->sum('quantity');

        // ========================================================================
        // RETOUR FINAL
        // ========================================================================

        return [
            'total_products' => $totalProducts,
            'total_variations' => $totalVariations,
            'total_stock_value' => $totalStockValue,
            'low_stock_count' => $lowStockCount,
            'out_of_stock_count' => $outOfStockCount,
            'total_items_in_stock' => $totalItemsInStock,
            'movements_today' => $movementsToday,
            'movements_this_week' => $movementsThisWeek,
            'movements_this_month' => $movementsThisMonth,
            // ✅ CORRECTION : Convertir en int et abs (PostgreSQL peut retourner des strings)
            'stock_in_today' => (int) abs((float) $stockInToday),
            'stock_out_today' => (int) abs((float) $stockOutToday),
            'stock_in_this_week' => (int) abs((float) $stockInThisWeek),
            'stock_out_this_week' => (int) abs((float) $stockOutThisWeek),
        ];
    }

    /**
     * Version ultra-optimisée avec une seule requête SQL (RECOMMANDÉ)
     * Beaucoup plus rapide pour les gros volumes
     */
    public function getStatisticsOptimized(): array
    {
        // Une seule requête pour tous les compteurs de stock
        $stockStats = DB::selectOne("
        SELECT
            COUNT(*) as total_products,
            COUNT(*) FILTER (WHERE stock_quantity = 0) as out_of_stock,
            COUNT(*) FILTER (WHERE stock_quantity > 0 AND stock_quantity <= COALESCE(low_stock_threshold, 10)) as low_stock,
            COUNT(*) FILTER (WHERE stock_quantity > 0 AND stock_quantity > COALESCE(low_stock_threshold, 10)) as in_stock,
            COALESCE(SUM(stock_quantity * COALESCE(cost_price, price, 0)), 0) as stock_value
        FROM products
        WHERE track_inventory = true
        AND deleted_at IS NULL
    ");

        // Total de variations
        $totalVariations = ProductVariation::whereHas('product', function ($q) {
            $q->where('track_inventory', true);
        })->count();

        // Mouvements (requête unifiée)
        $movements = DB::select("
        SELECT
            CASE
                WHEN created_at >= ? THEN 'today'
                WHEN created_at >= ? THEN 'week'
                ELSE 'month'
            END as period,
            type,
            COUNT(*) as movement_count,
            SUM(ABS(quantity)) as total_quantity
        FROM stock_movements
        WHERE created_at >= ?
        GROUP BY period, type
    ", [
            today()->toDateTimeString(),
            now()->startOfWeek()->toDateTimeString(),
            now()->startOfMonth()->toDateTimeString()
        ]);

        $movementStats = collect($movements);

        return [
            'total_products' => (int) $stockStats->total_products,
            'total_variations' => $totalVariations,
            'total_stock_value' => round((float) $stockStats->stock_value, 2),
            'low_stock_count' => (int) $stockStats->low_stock,
            'out_of_stock_count' => (int) $stockStats->out_of_stock,
            'total_items_in_stock' => (int) $stockStats->in_stock,

            'movements_today' => (int) $movementStats->where('period', 'today')->sum('movement_count'),
            'movements_this_week' => (int) $movementStats->whereIn('period', ['today', 'week'])->sum('movement_count'),
            'movements_this_month' => (int) $movementStats->sum('movement_count'),

            'stock_in_today' => (int) $movementStats->where('period', 'today')->where('type', 'in')->sum('total_quantity'),
            'stock_out_today' => (int) $movementStats->where('period', 'today')->where('type', 'out')->sum('total_quantity'),
            'stock_in_this_week' => (int) $movementStats->whereIn('period', ['today', 'week'])->where('type', 'in')->sum('total_quantity'),
            'stock_out_this_week' => (int) $movementStats->whereIn('period', ['today', 'week'])->where('type', 'out')->sum('total_quantity'),
        ];
    }

    // ========================================================================
    // MÉTHODES DE LECTURE - SIMPLIFIÉES
    // ========================================================================

    /**
     * Récupère les produits en stock faible (0 < stock < 10)
     */
    public function getLowStockItems(?int $threshold = null, int $perPage = 15)
    {
        $threshold = $threshold ?? 10;

        return Product::where('track_inventory', true)
            ->where('stock_quantity', '>', 0)
            ->where('stock_quantity', '<', $threshold)
            ->with(['brand', 'categories', 'media'])
            ->orderBy('stock_quantity', 'asc')
            ->paginate($perPage);
    }

    /**
     * Récupère les produits en rupture (stock = 0)
     */
    public function getOutOfStockItems(int $perPage = 15)
    {
        return Product::where('track_inventory', true)
            ->where('stock_quantity', '=', 0)
            ->with(['brand', 'categories', 'media'])
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);
    }

    public function export(array $filters = [], string $format = 'csv'): string
    {
        $format = strtolower($format);

        if (!in_array($format, ['csv'], true)) {
            throw new \InvalidArgumentException('Format d\'export non supporté. Format accepté : csv.');
        }

        $query = Product::query()
            ->with(['brand'])
            ->where('track_inventory', true);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        if (!empty($filters['category_id'])) {
            $query->whereHas('categories', function ($q) use ($filters) {
                $q->where('categories.id', $filters['category_id']);
            });
        }

        if (!empty($filters['low_stock_only'])) {
            $query->where('stock_quantity', '>', 0)
                ->whereRaw('stock_quantity <= COALESCE(low_stock_threshold, 10)');
        }

        $directory = 'exports/inventory';
        $filename = $directory . '/inventory-' . now()->format('Ymd-His') . '.csv';

        Storage::disk('public')->makeDirectory($directory);

        $handle = fopen(Storage::disk('public')->path($filename), 'w');

        if ($handle === false) {
            throw new \RuntimeException('Impossible de créer le fichier d\'export.');
        }

        fputcsv($handle, [
            'id',
            'name',
            'sku',
            'brand',
            'status',
            'stock_quantity',
            'low_stock_threshold',
            'price',
            'cost_price',
            'stock_value',
            'updated_at',
        ]);

        $query->orderBy('name')->chunkById(500, function ($products) use ($handle) {
            foreach ($products as $product) {
                $stockQuantity = (int) $product->stock_quantity;
                $unitCost = (float) ($product->cost_price ?? $product->price ?? 0);

                fputcsv($handle, [
                    $product->id,
                    $product->name,
                    $product->sku,
                    $product->brand?->name,
                    $product->status,
                    $stockQuantity,
                    $product->low_stock_threshold,
                    $product->price,
                    $product->cost_price,
                    round($stockQuantity * $unitCost, 2),
                    optional($product->updated_at)->toIso8601String(),
                ]);
            }
        });

        fclose($handle);

        return '/storage/' . $filename;
    }
    /**
     * Check if stock is sufficient
     */
    public function checkStock(int|string $productId, int $quantity, int|string|null $variationId = null): bool
    {
        if ($variationId) {
            $variation = ProductVariation::find($variationId);
            return $variation && $variation->stock_quantity >= $quantity;
        }

        $product = Product::find($productId);
        
        if (!$product) return false;

        return $product->stock_quantity >= $quantity;
    }

    /**
     * Reserve stock for an order.
     */
    public function reserveStock(int|string $productId, int $quantity, int|string|null $variationId = null, ?string $orderId = null): void
    {
        DB::transaction(function () use ($productId, $quantity, $variationId, $orderId) {
            if ($variationId) {
                $variation = ProductVariation::query()
                    ->with('product')
                    ->lockForUpdate()
                    ->findOrFail($variationId);

                if ((string) $variation->product_id !== (string) $productId) {
                    throw new \InvalidArgumentException('La variation sélectionnée ne correspond pas au produit fourni.');
                }

                $product = $variation->product;

                $this->performAdjustment(
                    target: $variation,
                    parentProduct: $product,
                    quantity: $quantity,
                    operation: StockOperation::SUB,
                    reason: StockReason::ORDER,
                    notes: "Commande #{$orderId}",
                    referenceId: $orderId,
                    referenceType: Order::class,
                );

                $product->update(['stock_quantity' => $product->variations()->sum('stock_quantity')]);

                if (method_exists($product, 'updateStockStatus')) {
                    $product->updateStockStatus();
                }

                return;
            }

            $product = Product::query()
                ->lockForUpdate()
                ->findOrFail($productId);

            $this->performAdjustment(
                target: $product,
                parentProduct: $product,
                quantity: $quantity,
                operation: StockOperation::SUB,
                reason: StockReason::ORDER,
                notes: "Commande #{$orderId}",
                referenceId: $orderId,
                referenceType: Order::class,
            );
        });
    }

    /**
     * Release stock for a product/variation after an order cancellation.
     */
    public function releaseStock(int|string $productId, int $quantity, int|string|null $variationId = null, ?string $orderId = null): void
    {
        DB::transaction(function () use ($productId, $quantity, $variationId, $orderId) {
            if ($variationId) {
                $variation = ProductVariation::query()
                    ->with('product')
                    ->lockForUpdate()
                    ->findOrFail($variationId);

                if ((string) $variation->product_id !== (string) $productId) {
                    throw new \InvalidArgumentException('La variation sélectionnée ne correspond pas au produit fourni.');
                }

                $product = $variation->product;

                $this->performAdjustment(
                    target: $variation,
                    parentProduct: $product,
                    quantity: $quantity,
                    operation: StockOperation::ADD,
                    reason: StockReason::RETURN,
                    notes: "Libération stock commande #{$orderId}",
                    referenceId: $orderId,
                    referenceType: Order::class,
                );

                $product->update(['stock_quantity' => $product->variations()->sum('stock_quantity')]);

                if (method_exists($product, 'updateStockStatus')) {
                    $product->updateStockStatus();
                }

                return;
            }

            $product = Product::query()
                ->lockForUpdate()
                ->findOrFail($productId);

            $this->performAdjustment(
                target: $product,
                parentProduct: $product,
                quantity: $quantity,
                operation: StockOperation::ADD,
                reason: StockReason::RETURN,
                notes: "Libération stock commande #{$orderId}",
                referenceId: $orderId,
                referenceType: Order::class,
            );
        });
    }

    /**
     * Release all reserved stock for an order. Must be called inside the order cancellation transaction.
     */
    public function releaseOrderStock(Order $order): void
    {
        foreach ($order->items as $item) {
            $this->releaseStock(
                productId: $item->product_id,
                quantity: (int) $item->quantity,
                variationId: $item->variation_id,
                orderId: $order->id,
            );
        }
    }
}
