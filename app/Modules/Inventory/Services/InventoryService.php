<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;
use Modules\Catalogue\Models\Product;
use Modules\Catalogue\Models\ProductVariation;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Enums\StockOperation;
use Modules\Inventory\Enums\StockReason;
use Illuminate\Pagination\LengthAwarePaginator;

class InventoryService
{

    public function adjustStock(array $data): array
    {
        Log::info('InventoryService::adjustStock START', [
            'product_id' => $data['product_id'],
            'reason' => $data['reason'],
            'has_variations' => isset($data['variations'])
        ]);

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

    private function performAdjustment(
        Model $target,
        Product $parentProduct,
        int $quantity,
        StockOperation $operation,
        StockReason $reason,
        ?string $notes
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

        Log::info('Stock counts', [
            'total_products' => $totalProducts,
            'in_stock' => $totalItemsInStock,
            'low_stock' => $lowStockCount,
            'out_of_stock' => $outOfStockCount
        ]);

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
        // TODO: Implémenter l'export selon vos besoins
        throw new \BadMethodCallException('Export not implemented yet');
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
     * Reserve stock for an order
     */
    public function reserveStock(int|string $productId, int $quantity, int|string|null $variationId = null, ?string $orderId = null): void
    {
        // Reuse adjustStock to ensure consistency and history
        if ($variationId) {
            $this->adjustStock([
                'product_id' => $productId,
                'reason' => StockReason::ORDER->value,
                'variations' => [
                    [
                        'id' => $variationId,
                        'quantity' => $quantity,
                        'operation' => StockOperation::SUB->value
                    ]
                ],
                'notes' => "Order #{$orderId}"
            ]);
        } else {
            $this->adjustStock([
                'product_id' => $productId,
                'quantity' => $quantity,
                'operation' => StockOperation::SUB->value,
                'reason' => StockReason::ORDER->value,
                'notes' => "Order #{$orderId}"
            ]);
        }
    }
}
