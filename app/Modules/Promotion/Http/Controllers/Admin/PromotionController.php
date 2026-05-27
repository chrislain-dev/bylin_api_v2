<?php

declare(strict_types=1);

namespace Modules\Promotion\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Promotion\Models\Promotion;
use Modules\Promotion\Services\PromotionService;
use Modules\Promotion\Http\Requests\BulkPromotionIdsRequest;
use Modules\Promotion\Http\Requests\StorePromotionRequest;
use Modules\Promotion\Http\Requests\UpdatePromotionRequest;

class PromotionController extends ApiController
{
    public function __construct(
        protected PromotionService $promotionService
    ) {}

    /**
     * List promotions avec filtres avancés
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Promotion::query()->withCount('usages');

            // Filtre par recherche (insensible à la casse)
            if ($request->filled('search')) {
                $search = $request->search;
                $driver = config('database.default');

                $query->where(function ($q) use ($search, $driver) {
                    // PostgreSQL: utiliser ILIKE (natif et performant)
                    if ($driver === 'pgsql') {
                        $q->where('name', 'ILIKE', "%{$search}%")
                          ->orWhere('code', 'ILIKE', "%{$search}%")
                          ->orWhere('description', 'ILIKE', "%{$search}%");
                    }
                    // MySQL/MariaDB: LIKE est déjà insensible à la casse par défaut
                    else {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('code', 'like', "%{$search}%")
                          ->orWhere('description', 'like', "%{$search}%");
                    }
                });
            }

            // Filtre par type
            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }

            // Filtre par statut
            if ($request->filled('status')) {
                $status = $request->status;

                match($status) {
                    'active' => $query->active(),
                    'inactive' => $query->where('is_active', false),
                    'expired' => $query->expired(),
                    'upcoming' => $query->upcoming(),
                    default => null
                };
            }

            // Filtre actif/inactif simple
            if ($request->has('active')) {
                $query->where('is_active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
            }

            // Gestion des éléments supprimés
            if ($request->boolean('with_trashed')) {
                $query->withTrashed();
            } elseif ($request->boolean('only_trashed')) {
                $query->onlyTrashed();
            }

            // Tri
            $sortBy = $request->get('sort_by', 'created_at');
            $sortDirection = $request->get('sort_direction', 'desc');

            $allowedSorts = ['name', 'code', 'value', 'usage_count', 'starts_at', 'expires_at', 'created_at'];
            if (in_array($sortBy, $allowedSorts)) {
                $query->orderBy($sortBy, $sortDirection);
            }

            // Pagination
            $perPage = min($request->get('per_page', 15), 100);
            $promotions = $query->paginate($perPage);

            return $this->successResponse($promotions);

        } catch (\Exception $e) {
            Log::error('Error in promotion index', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse(
                'Erreur lors du chargement des promotions: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Créer une promotion
     */
    public function store(StorePromotionRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Normalise le code si fourni
            if (isset($validated['code'])) {
                $validated['code'] = strtoupper(trim($validated['code']));
            }

            $promotion = Promotion::create($validated);

            return $this->createdResponse($promotion, 'Promotion créée avec succès');

        } catch (\Exception $e) {
            Log::error('Error creating promotion', [
                'message' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Erreur lors de la création: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Afficher une promotion
     */
    public function show(string $id): JsonResponse
    {
        try {
            $promotion = Promotion::withCount('usages')
                ->with(['usages' => function ($query) {
                    $query->latest()->limit(10);
                }])
                ->withTrashed()
                ->findOrFail($id);

            return $this->successResponse($promotion);

        } catch (\Exception $e) {
            Log::error('Error showing promotion', [
                'id' => $id,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Promotion introuvable',
                404
            );
        }
    }

    /**
     * Mettre à jour une promotion
     */
    public function update(string $id, UpdatePromotionRequest $request): JsonResponse
    {
        try {
            $promotion = Promotion::findOrFail($id);
            $validated = $request->validated();

            if (isset($validated['code'])) {
                $newCode = strtoupper(trim($validated['code']));
                if ($newCode === $promotion->code) {
                    unset($validated['code']);
                }
            }

            $promotion->update($validated);

            Log::info('Promotion updated', ['id' => $promotion->id]);

            return $this->successResponse($promotion, 'Promotion mise à jour avec succès');
        } catch (\Exception $e) {
            Log::error('Error updating promotion', [
                'id' => $id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse(
                'Erreur lors de la mise à jour: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Supprimer une promotion
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $promotion = Promotion::findOrFail($id);
            $promotion->delete();

            Log::info('Promotion deleted', ['id' => $id]);

            return $this->successResponse(null, 'Promotion supprimée avec succès');

        } catch (\Exception $e) {
            Log::error('Error deleting promotion', [
                'id' => $id,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Erreur lors de la suppression',
                500
            );
        }
    }

    /**
     * Restaurer une promotion supprimée
     */
    public function restore(string $id): JsonResponse
    {
        try {
            $promotion = Promotion::onlyTrashed()->findOrFail($id);
            $promotion->restore();

            Log::info('Promotion restored', ['id' => $id]);

            return $this->successResponse($promotion, 'Promotion restaurée avec succès');

        } catch (\Exception $e) {
            Log::error('Error restoring promotion', [
                'id' => $id,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Erreur lors de la restauration',
                500
            );
        }
    }

    /**
     * Suppression multiple
     */
    public function bulkDestroy(BulkPromotionIdsRequest $request): JsonResponse
    {
        try {
            $count = Promotion::query()->whereIn('id', $request->validated('ids'))->delete();

            Log::info('Bulk delete promotions', ['count' => $count]);

            return $this->successResponse(
                null,
                "{$count} promotion(s) supprimée(s) avec succès"
            );

        } catch (\Exception $e) {
            Log::error('Error bulk deleting promotions', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Erreur lors de la suppression multiple',
                500
            );
        }
    }

    /**
     * Restauration multiple
     */
    public function bulkRestore(BulkPromotionIdsRequest $request): JsonResponse
    {
        try {
            $promotions = Promotion::onlyTrashed()
                ->whereIn('id', $request->validated('ids'))
                ->get();

            $count = 0;
            foreach ($promotions as $promotion) {
                $promotion->restore();
                $count++;
            }

            Log::info('Bulk restore promotions', ['count' => $count]);

            return $this->successResponse(
                null,
                "{$count} promotion(s) restaurée(s) avec succès"
            );

        } catch (\Exception $e) {
            Log::error('Error bulk restoring promotions', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Erreur lors de la restauration multiple',
                500
            );
        }
    }

    /**
     * Statistiques des promotions
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = [
                'total' => Promotion::count(),
                'active' => Promotion::where('is_active', true)->count(),
                'inactive' => Promotion::where('is_active', false)->count(),
                'expired' => Promotion::whereNotNull('expires_at')
                    ->where('expires_at', '<', now())
                    ->count(),
                'upcoming' => Promotion::whereNotNull('starts_at')
                    ->where('starts_at', '>', now())
                    ->count(),
                'by_type' => [
                    'percentage' => Promotion::where('type', 'percentage')->count(),
                    'fixed_amount' => Promotion::where('type', 'fixed_amount')->count(),
                    'buy_x_get_y' => Promotion::where('type', 'buy_x_get_y')->count(),
                ],
                'total_usage' => Promotion::sum('usage_count') ?? 0,
                'total_discount_amount' => 0
            ];

            // Calcul du montant total de réduction
            try {
                $totalDiscount = DB::table('promotion_usages')
                    ->sum('discount_amount');
                $stats['total_discount_amount'] = $totalDiscount ?? 0;
            } catch (\Exception $e) {
                Log::warning('Could not calculate total discount amount', [
                    'message' => $e->getMessage()
                ]);
            }

            return $this->successResponse($stats);

        } catch (\Exception $e) {
            Log::error('Error fetching statistics', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Erreur lors du chargement des statistiques',
                500
            );
        }
    }
}
