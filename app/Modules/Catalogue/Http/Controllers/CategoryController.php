<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalogue\Models\Category;
use Modules\Catalogue\Resources\CategoryResource;
use Modules\Core\Http\Controllers\ApiController;

/**
 * Category Controller (Public API pour les clients)
 *
 * Gère l'affichage public des catégories pour le site e-commerce.
 * Optimisé pour la navigation, le mega menu et les pages catégories.
 */
class CategoryController extends ApiController
{
    /**
     * Liste toutes les catégories actives (optimisé pour la navigation)
     *
     * GET /api/v1/catalog/categories
     *
     * Query params:
     * - is_visible_in_menu: Filter by menu visibility (boolean)
     * - level: Filter by hierarchy level (0=Genre, 1=Type, 2=Category)
     * - parent_id: Filter by parent category
     * - with_children: Include children in response (boolean)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Category::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name');

        // Filtrer par visibilité menu (pour la navigation du header)
        if ($request->filled('is_visible_in_menu')) {
            $query->where('is_visible_in_menu', (bool) $request->is_visible_in_menu);
        }

        // Filtrer par niveau hiérarchique
        if ($request->filled('level')) {
            $query->where('level', (int) $request->level);
        }

        // Filtrer par parent
        if ($request->has('parent_id')) {
            if ($request->parent_id === 'null' || $request->parent_id === '') {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', $request->parent_id);
            }
        }

        // Eager load children si demandé (utile pour le mega menu)
        if ($request->boolean('with_children')) {
            $query->with(['children' => function ($q) {
                $q->active()->orderBy('sort_order');
            }]);
        }

        // Compter les produits
        $query->withCount('products');

        $categories = $query->get();

        return $this->successResponse(
            CategoryResource::collection($categories)
        );
    }

    /**
     * Récupère l'arbre complet des catégories (pour mega menu)
     *
     * GET /api/v1/catalog/categories/tree
     *
     * Retourne la hiérarchie complète optimisée pour construire
     * la navigation avec mega menu à 3 niveaux.
     *
     * @return JsonResponse
     */
    public function tree(): JsonResponse
    {
        $tree = Category::with([
            'children' => function ($query) {
                $query->active()
                    ->where('is_visible_in_menu', true)
                    ->orderBy('sort_order')
                    ->with(['children' => function ($q) {
                        $q->active()
                            ->orderBy('sort_order')
                            ->withCount('products');
                    }])
                    ->withCount('products');
            }
        ])
            ->active()
            ->where('is_visible_in_menu', true)
            ->root()
            ->orderBy('sort_order')
            ->withCount('products')
            ->get();

        return $this->successResponse(
            CategoryResource::collection($tree)
        );
    }

    /**
     * Récupère une catégorie par son slug
     *
     * GET /api/v1/catalog/categories/{slug}
     *
     * @param string $slug Slug de la catégorie
     * @return JsonResponse
     */
    public function show(string $slug): JsonResponse
    {
        $category = Category::where('slug', $slug)
            ->active()
            ->with([
                'parent',
                'children' => function ($query) {
                    $query->active()
                        ->orderBy('sort_order')
                        ->withCount('products');
                }
            ])
            ->withCount(['products', 'children'])
            ->firstOrFail();

        return $this->successResponse(
            new CategoryResource($category)
        );
    }

    /**
     * Récupère les produits d'une catégorie
     *
     * GET /api/v1/catalog/categories/{slug}/products
     *
     * Query params:
     * - per_page: Nombre d'éléments par page (défaut: 15)
     * - sort: Tri (newest, price_asc, price_desc, popular)
     * - include_descendants: Inclure les produits des sous-catégories
     *
     * @param string $slug Slug de la catégorie
     * @param Request $request
     * @return JsonResponse
     */
    public function products(string $slug, Request $request): JsonResponse
    {
        $category = Category::where('slug', $slug)
            ->active()
            ->firstOrFail();

        // Construction de la requête produits
        $query = $category->products()
            ->with(['brand', 'categories', 'variants'])
            ->where('status', 'active')
            ->where('is_visible', true);

        // 🔥 OPTIMISATION: Inclure les produits des sous-catégories via path
        if ($request->boolean('include_descendants', true)) {
            // Récupère tous les descendants via le path (1 seule requête)
            $descendantIds = Category::where('path', 'like', "{$category->path}/%")
                ->pluck('id')
                ->toArray();

            if (!empty($descendantIds)) {
                $query->orWhereHas('categories', function ($q) use ($descendantIds) {
                    $q->whereIn('categories.id', $descendantIds);
                });
            }
        }

        // Tri
        $sort = $request->get('sort', 'newest');
        match ($sort) {
            'price_asc' => $query->orderBy('price', 'asc'),
            'price_desc' => $query->orderBy('price', 'desc'),
            'popular' => $query->orderBy('views_count', 'desc'),
            'name_asc' => $query->orderBy('name', 'asc'),
            'name_desc' => $query->orderBy('name', 'desc'),
            default => $query->latest(),
        };

        $perPage = min((int) $request->get('per_page', 15), 50);
        $products = $query->paginate($perPage);

        return $this->paginatedResponse($products);
    }

    /**
     * Récupère le fil d'Ariane (breadcrumb) d'une catégorie
     *
     * GET /api/v1/catalog/categories/{slug}/breadcrumb
     *
     * ⚠️ DEPRECATED: Utiliser category.breadcrumbs directement
     * Cette méthode est gardée pour compatibilité
     *
     * @param string $slug Slug de la catégorie
     * @return JsonResponse
     */
    public function breadcrumb(string $slug): JsonResponse
    {
        $category = Category::where('slug', $slug)
            ->active()
            ->firstOrFail();

        // Les breadcrumbs sont maintenant calculés automatiquement
        return $this->successResponse([
            'breadcrumbs' => $category->breadcrumbs
        ]);
    }

    /**
     * Récupère les catégories en vedette (featured)
     *
     * GET /api/v1/catalog/categories/featured
     *
     * Utile pour afficher des catégories promotionnelles
     * sur la page d'accueil ou dans des bannières.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function featured(Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 6), 20);

        $categories = Category::active()
            ->where('is_featured', true)
            ->where('is_visible_in_menu', true)
            ->withCount('products')
            ->orderBy('sort_order')
            ->limit($limit)
            ->get();

        return $this->successResponse(
            CategoryResource::collection($categories)
        );
    }

    /**
     * Recherche de catégories
     *
     * GET /api/v1/catalog/categories/search?q={query}
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->get('q', '');

        if (strlen($query) < 2) {
            return $this->successResponse([]);
        }

        $categories = Category::active()
            ->where(function ($q) use ($query) {
                $q->where('name', 'ilike', "%{$query}%")
                    ->orWhere('description', 'ilike', "%{$query}%");
            })
            ->withCount('products')
            ->orderBy('sort_order')
            ->limit(10)
            ->get();

        return $this->successResponse(
            CategoryResource::collection($categories)
        );
    }

    /**
     * Récupère les catégories par genre (Homme, Femme, Enfant, Mixte)
     *
     * GET /api/v1/catalog/categories/by-genre/{genre}
     *
     * @param string $genre Slug du genre (homme, femme, enfant, mixte)
     * @return JsonResponse
     */
    public function byGenre(string $genre): JsonResponse
    {
        $genreCategory = Category::where('slug', $genre)
            ->active()
            ->root()
            ->firstOrFail();

        $categories = Category::with(['children' => function ($query) {
            $query->active()
                ->orderBy('sort_order')
                ->withCount('products');
        }])
            ->where('parent_id', $genreCategory->id)
            ->active()
            ->orderBy('sort_order')
            ->withCount('products')
            ->get();

        return $this->successResponse([
            'genre' => new CategoryResource($genreCategory),
            'categories' => CategoryResource::collection($categories),
        ]);
    }

    /**
     * Récupère les catégories similaires/connexes
     *
     * GET /api/v1/catalog/categories/{slug}/related
     *
     * Suggère des catégories du même niveau ou parent.
     *
     * @param string $slug
     * @param Request $request
     * @return JsonResponse
     */
    public function related(string $slug, Request $request): JsonResponse
    {
        $category = Category::where('slug', $slug)
            ->active()
            ->firstOrFail();

        $limit = min((int) $request->get('limit', 4), 10);

        // Récupère les catégories sœurs (même parent)
        $related = Category::active()
            ->where('parent_id', $category->parent_id)
            ->where('id', '!=', $category->id)
            ->withCount('products')
            ->orderBy('sort_order')
            ->limit($limit)
            ->get();

        return $this->successResponse(
            CategoryResource::collection($related)
        );
    }
}
