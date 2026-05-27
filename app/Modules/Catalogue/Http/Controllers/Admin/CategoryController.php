<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Catalogue\Models\Category;
use Modules\Catalogue\Services\CategoryService;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Catalogue\Http\Requests\StoreCategoryRequest;
use Modules\Catalogue\Http\Requests\UpdateCategoryRequest;
use Modules\Catalogue\Http\Requests\BulkCategoryIdsRequest;
use Modules\Catalogue\Http\Requests\MoveCategoryRequest;
use Modules\Catalogue\Http\Requests\ReorderCategoriesRequest;

/**
 * Contrôleur de gestion des catégories
 *
 * Gère toutes les opérations CRUD pour les catégories, ainsi que les opérations
 * avancées comme le déplacement, le réordonnancement et la gestion hiérarchique.
 */
class CategoryController extends ApiController
{
    /**
     * Constructeur du contrôleur
     *
     * @param CategoryService $categoryService Service de gestion des catégories
     */
    public function __construct(
        private CategoryService $categoryService
    ) {}

    /**
     * Liste toutes les catégories avec filtres et pagination
     *
     * Paramètres de requête acceptés :
     * - search : Terme de recherche
     * - parent_id : Filtrer par catégorie parente
     * - level : Filtrer par niveau hiérarchique
     * - is_active : Filtrer par statut actif/inactif
     * - is_visible_in_menu : Filtrer par visibilité menu
     * - is_featured : Filtrer par mise en avant
     * - only_root : Afficher uniquement les catégories racines
     * - only_trashed : Afficher uniquement les catégories supprimées
     * - with_trashed : Inclure les catégories supprimées
     * - per_page : Nombre d'éléments par page (défaut: 15)
     *
     * @param Request $request Requête HTTP
     * @return JsonResponse Liste paginée des catégories
     */
    public function index(Request $request): JsonResponse
    {
        $query = Category::query();

        // Eager loading des relations
        $query->with(['parent']);

        // Compter les enfants et produits
        $query->withCount(['children', 'products']);

        // Recherche
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Filtrage par parent
        if ($request->has('parent_id')) {
            if ($request->parent_id === 'null' || $request->parent_id === '') {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', $request->parent_id);
            }
        }

        // Filtrage par niveau
        if ($request->has('level') && $request->level !== 'all' && $request->level !== null) {
            $query->where('level', (int) $request->level);
        }

        // Filtre catégories racines uniquement
        if ($request->boolean('only_root')) {
            $query->root();
        }

        // Filtrage par statut
        if ($request->has('is_active') && $request->is_active !== 'all' && $request->is_active !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filtrage par visibilité menu
        if ($request->filled('is_visible_in_menu')) {
            $query->where('is_visible_in_menu', (bool) $request->is_visible_in_menu);
        }

        // Filtrage par mise en avant
        if ($request->filled('is_featured')) {
            $query->where('is_featured', (bool) $request->is_featured);
        }

        // Gestion des suppressions
        if ($request->boolean('only_trashed')) {
            $query->onlyTrashed();
        } elseif ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        // Tri
        $query->ordered();

        $categories = $query->paginate($request->per_page ?? 15);

        return $this->successResponse($categories);
    }

    /**
     * Récupère l'arbre complet des catégories
     *
     * @param Request $request Requête HTTP
     * @return JsonResponse Arbre hiérarchique
     */
    public function tree(Request $request): JsonResponse
    {
        $query = Category::query()
            ->with(['children.children.children']) // 3 niveaux max
            ->withCount('products')
            ->root()
            ->active()
            ->ordered();

        $tree = $query->get();

        return $this->successResponse($tree);
    }

    /**
     * Crée une nouvelle catégorie
     *
     * @param StoreCategoryRequest $request Requête de création validée
     * @return JsonResponse Catégorie créée avec message de succès
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $category = $this->categoryService->createCategory($validated);

        return $this->createdResponse($category, 'Catégorie créée avec succès');
    }

    /**
     * Affiche les détails d'une catégorie spécifique
     *
     * @param string $id Identifiant de la catégorie
     * @return JsonResponse Détails de la catégorie
     */
    public function show(string $id): JsonResponse
    {
        $category = Category::withTrashed()
            ->with(['parent', 'children'])
            ->withCount(['children', 'products'])
            ->findOrFail($id);

        return $this->successResponse($category);
    }

    /**
     * Met à jour une catégorie existante
     *
     * @param UpdateCategoryRequest $request Requête de mise à jour validée
     * @param string $id Identifiant de la catégorie
     * @return JsonResponse Catégorie mise à jour avec message de succès
     */
    public function update(UpdateCategoryRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();

        $category = $this->categoryService->updateCategory($id, $validated);

        return $this->successResponse($category, 'Catégorie mise à jour avec succès');
    }

    /**
     * Supprime une catégorie (soft delete)
     *
     * @param string $id Identifiant de la catégorie
     * @return JsonResponse Message de confirmation
     */
    public function destroy(string $id): JsonResponse
    {
        $this->categoryService->deleteCategory($id);

        return $this->successResponse(null, 'Catégorie supprimée avec succès');
    }

    /**
     * Restaure une catégorie supprimée
     *
     * @param string $id Identifiant de la catégorie
     * @return JsonResponse Catégorie restaurée avec message de succès
     */
    public function restore(string $id): JsonResponse
    {
        $category = $this->categoryService->restoreCategory($id);

        return $this->successResponse($category, 'Catégorie restaurée avec succès');
    }

    /**
     * Supprime définitivement une catégorie de la base de données
     *
     * @param string $id Identifiant de la catégorie
     * @return JsonResponse Message de confirmation
     */
    public function forceDelete(string $id): JsonResponse
    {
        $this->categoryService->forceDeleteCategory($id);

        return $this->successResponse(null, 'Catégorie supprimée définitivement');
    }

    /**
     * Supprime plusieurs catégories en masse (soft delete)
     *
     * @param Request $request Requête contenant les IDs
     * @return JsonResponse Message de confirmation
     */
    public function bulkDestroy(BulkCategoryIdsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $count = 0;
        $errors = [];

        foreach ($validated['ids'] as $id) {
            try {
                $this->categoryService->deleteCategory($id);
                $count++;
            } catch (\Exception $e) {
                $errors[] = [
                    'id' => $id,
                    'error' => $e->getMessage()
                ];
            }
        }

        $message = "{$count} catégorie(s) supprimée(s) avec succès";

        if (!empty($errors)) {
            $message .= ". " . count($errors) . " erreur(s) rencontrée(s).";
        }

        return $this->successResponse([
            'deleted' => $count,
            'errors' => $errors
        ], $message);
    }

    /**
     * Restaure plusieurs catégories en masse
     *
     * @param Request $request Requête contenant les IDs
     * @return JsonResponse Message de confirmation
     */
    public function bulkRestore(BulkCategoryIdsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $count = Category::onlyTrashed()
            ->whereIn('id', $validated['ids'])
            ->restore();

        return $this->successResponse(
            null,
            "{$count} catégorie(s) restaurée(s) avec succès"
        );
    }

    /**
     * Supprime définitivement plusieurs catégories en masse
     *
     * @param Request $request Requête contenant les IDs
     * @return JsonResponse Message de confirmation
     */
    public function bulkForceDelete(BulkCategoryIdsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $categories = Category::withTrashed()
            ->whereIn('id', $validated['ids'])
            ->get();

        foreach ($categories as $category) {
            $this->categoryService->forceDeleteCategory($category->id);
        }

        $count = count($validated['ids']);

        return $this->successResponse(
            null,
            "{$count} catégorie(s) supprimée(s) définitivement"
        );
    }

    /**
     * Déplace une catégorie vers un nouveau parent
     *
     * @param Request $request Requête contenant le nouveau parent
     * @param string $id Identifiant de la catégorie à déplacer
     * @return JsonResponse Catégorie déplacée
     */
    public function move(MoveCategoryRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();

        $category = $this->categoryService->moveCategory(
            $id,
            $validated['parent_id'] ?? null
        );

        return $this->successResponse($category, 'Catégorie déplacée avec succès');
    }

    /**
     * Réordonne les catégories
     *
     * @param Request $request Requête contenant le nouvel ordre
     * @return JsonResponse Message de confirmation
     */
    public function reorder(ReorderCategoriesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $order = collect($validated['order'])->pluck('sort_order', 'id')->toArray();

        $this->categoryService->reorderCategories($order);

        return $this->successResponse(null, 'Catégories réordonnées avec succès');
    }

    /**
     * Retourne les statistiques des catégories
     *
     * Statistiques disponibles :
     * - total : Nombre total de catégories
     * - active : Nombre de catégories actives
     * - inactive : Nombre de catégories inactives
     * - root : Nombre de catégories racines
     * - with_products : Nombre de catégories avec produits
     * - trashed : Nombre de catégories supprimées
     *
     * @return JsonResponse Statistiques des catégories
     */
    public function statistics(): JsonResponse
    {
        $stats = [
            'total' => Category::withTrashed()->count(),
            'active' => Category::where('is_active', true)->count(),
            'inactive' => Category::where('is_active', false)->count(),
            'root' => Category::root()->count(),
            'with_products' => Category::has('products')->count(),
            'trashed' => Category::onlyTrashed()->count(),
            'by_level' => Category::selectRaw('level, COUNT(*) as count')
                ->groupBy('level')
                ->pluck('count', 'level')
                ->toArray(),
        ];

        return $this->successResponse($stats);
    }

    /**
     * Récupère le fil d'Ariane (breadcrumb) d'une catégorie
     *
     * @param string $id Identifiant de la catégorie
     * @return JsonResponse Chemin hiérarchique
     */
    public function breadcrumb(string $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        $breadcrumb = $category->ancestors()
            ->push($category)
            ->map(fn($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'level' => $cat->level,
            ]);

        return $this->successResponse($breadcrumb);
    }
}
