<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Catalogue\Models\Attribute;
use Modules\Catalogue\Services\AttributeService;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Catalogue\Http\Requests\StoreAttributeRequest;
use Modules\Catalogue\Http\Requests\UpdateAttributeRequest;
use Modules\Catalogue\Http\Requests\BulkAttributeIdsRequest;
use Modules\Catalogue\Http\Requests\ReorderAttributesRequest;

/**
 * Contrôleur de gestion des attributs produits
 *
 * Gère les opérations CRUD pour les attributs (Couleur, Taille, Matière, etc.)
 * et leurs valeurs associées
 */
class AttributeController extends ApiController
{
    /**
     * Constructeur du contrôleur
     *
     * @param AttributeService $attributeService Service de gestion des attributs
     */
    public function __construct(
        private AttributeService $attributeService
    ) {}

    /**
     * Liste tous les attributs avec filtres et pagination
     *
     * Paramètres de requête acceptés :
     * - search : Terme de recherche
     * - type : Filtrer par type (text, select, color, size, boolean)
     * - is_filterable : Filtrer par statut filtrable
     * - with_values : Inclure les valeurs d'attributs
     * - per_page : Nombre d'éléments par page (défaut: 15)
     *
     * @param Request $request Requête HTTP
     * @return JsonResponse Liste paginée des attributs
     */
    public function index(Request $request): JsonResponse
    {
        $query = Attribute::query();

        // Inclure les valeurs si demandé
        if ($request->boolean('with_values')) {
            $query->with('values');
        }

        // Recherche
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('code', 'like', '%' . $request->search . '%');
            });
        }

        // Filtrage par type
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Filtrage par statut filtrable
        if ($request->filled('is_filterable')) {
            $query->where('is_filterable', (bool) $request->is_filterable);
        }

        // Gestion des suppressions
        if ($request->boolean('only_trashed')) {
            $query->onlyTrashed();
        } elseif ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        // Tri
        $query->orderBy('sort_order')->orderBy('name');

        $attributes = $query->paginate($request->per_page ?? 15);

        return $this->successResponse($attributes);
    }

    /**
     * Crée un nouvel attribut
     *
     * @param StoreAttributeRequest $request Requête de création validée
     * @return JsonResponse Attribut créé avec message de succès
     */
    public function store(StoreAttributeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $attribute = $this->attributeService->createAttribute($validated);

        return $this->createdResponse($attribute->load('values'), 'Attribut créé avec succès');
    }

    /**
     * Affiche les détails d'un attribut spécifique
     *
     * @param string $id Identifiant de l'attribut
     * @return JsonResponse Détails de l'attribut
     */
    public function show(string $id): JsonResponse
    {
        $attribute = Attribute::withTrashed()
            ->with(['values' => function ($query) {
                $query->orderBy('sort_order')->orderBy('value');
            }])
            ->findOrFail($id);

        return $this->successResponse($attribute);
    }

    /**
     * Met à jour un attribut existant
     *
     * @param UpdateAttributeRequest $request Requête de mise à jour validée
     * @param string $id Identifiant de l'attribut
     * @return JsonResponse Attribut mis à jour avec message de succès
     */
    public function update(UpdateAttributeRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();

        $attribute = $this->attributeService->updateAttribute($id, $validated);

        return $this->successResponse($attribute->load('values'), 'Attribut mis à jour avec succès');
    }

    /**
     * Supprime un attribut (soft delete)
     *
     * @param string $id Identifiant de l'attribut
     * @return JsonResponse Message de confirmation
     */
    public function destroy(string $id): JsonResponse
    {
        $this->attributeService->deleteAttribute($id);

        return $this->successResponse(null, 'Attribut supprimé avec succès');
    }

    /**
     * Restaure un attribut supprimé
     *
     * @param string $id Identifiant de l'attribut
     * @return JsonResponse Attribut restauré avec message de succès
     */
    public function restore(string $id): JsonResponse
    {
        $attribute = Attribute::onlyTrashed()->findOrFail($id);
        $attribute->restore();

        return $this->successResponse($attribute, 'Attribut restauré avec succès');
    }

    /**
     * Supprime définitivement un attribut de la base de données
     *
     * @param string $id Identifiant de l'attribut
     * @return JsonResponse Message de confirmation
     */
    public function forceDelete(string $id): JsonResponse
    {
        $attribute = Attribute::withTrashed()->findOrFail($id);

        // Vérifier si l'attribut est utilisé par des produits
        if ($attribute->products()->exists()) {
            return $this->errorResponse(
                'Impossible de supprimer définitivement cet attribut car il est utilisé par des produits',
                422
            );
        }

        $attribute->forceDelete();

        return $this->successResponse(null, 'Attribut supprimé définitivement');
    }

    /**
     * Supprime plusieurs attributs en masse (soft delete)
     *
     * @param Request $request Requête contenant les IDs
     * @return JsonResponse Message de confirmation
     */
    public function bulkDestroy(BulkAttributeIdsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        Attribute::whereIn('id', $validated['ids'])->delete();

        return $this->successResponse(
            null,
            count($validated['ids']) . ' attribut(s) supprimé(s) avec succès'
        );
    }

    /**
     * Restaure plusieurs attributs en masse
     *
     * @param Request $request Requête contenant les IDs
     * @return JsonResponse Message de confirmation
     */
    public function bulkRestore(BulkAttributeIdsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $count = Attribute::onlyTrashed()
            ->whereIn('id', $validated['ids'])
            ->restore();

        return $this->successResponse(
            null,
            $count . ' attribut(s) restauré(s) avec succès'
        );
    }

    public function bulkForceDelete(BulkAttributeIdsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $deleted = 0;
        $errors = [];

        $attributes = Attribute::withTrashed()
            ->whereIn('id', $validated['ids'])
            ->withCount('products')
            ->get();

        foreach ($attributes as $attribute) {
            if ($attribute->products_count > 0) {
                $errors[] = [
                    'id' => $attribute->id,
                    'error' => 'Attribut utilisé par des produits',
                ];
                continue;
            }

            $attribute->forceDelete();
            $deleted++;
        }

        return $this->successResponse([
            'deleted' => $deleted,
            'errors' => $errors,
        ], $deleted . ' attribut(s) supprimé(s) définitivement');
    }

    /**
     * Retourne les statistiques des attributs
     *
     * Statistiques disponibles :
     * - total : Nombre total d'attributs
     * - by_type : Répartition par type
     * - filterable : Nombre d'attributs filtrables
     * - with_products : Nombre d'attributs utilisés par des produits
     * - trashed : Nombre d'attributs supprimés
     *
     * @return JsonResponse Statistiques des attributs
     */
    public function statistics(): JsonResponse
    {
        $stats = [
            'total' => Attribute::withTrashed()->count(),
            'by_type' => Attribute::selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type'),
            'filterable' => Attribute::where('is_filterable', true)->count(),
            'with_products' => Attribute::has('products')->count(),
            'trashed' => Attribute::onlyTrashed()->count(),
        ];

        return $this->successResponse($stats);
    }

    /**
     * Réorganise les attributs
     *
     * @param Request $request Requête contenant l'ordre des attributs
     * @return JsonResponse Message de confirmation
     */
    public function reorder(ReorderAttributesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        foreach ($validated['orders'] as $order) {
            Attribute::where('id', $order['id'])
                ->update(['sort_order' => $order['sort_order']]);
        }

        return $this->successResponse(null, 'Ordre mis à jour avec succès');
    }
}
