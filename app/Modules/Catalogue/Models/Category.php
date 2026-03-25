<?php

declare(strict_types=1);

namespace Modules\Catalogue\Models;

use Illuminate\Support\Collection;
use Modules\Core\Models\BaseModel;
use Illuminate\Support\Facades\Log;
use Modules\Core\Traits\Searchable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Modèle Category (Catégorie)
 *
 * Représente une catégorie de produits avec support de hiérarchie parent-enfant.
 * Utilisé pour organiser les produits en arborescence (ex: Homme > Hauts > T-shirts).
 *
 * @property string $id Identifiant unique (UUID)
 * @property string|null $parent_id ID de la catégorie parente
 * @property string $name Nom de la catégorie
 * @property string $slug Slug URL-friendly
 * @property string|null $description Description détaillée
 * @property string|null $image Chemin de l'image de bannière
 * @property string|null $image_url URL complète de l'image
 * @property string|null $icon Icône pour l'interface
 * @property string|null $color Couleur thème (hex)
 * @property int $level Niveau hiérarchique (0 = racine)
 * @property string|null $path Chemin hiérarchique (/uuid1/uuid2/uuid3)
 * @property bool $is_active Catégorie active/visible
 * @property bool $is_visible_in_menu Visible dans le menu
 * @property bool $is_featured Mise en avant
 * @property int $sort_order Ordre d'affichage
 * @property array|null $meta_data Métadonnées JSON
 * @property string|null $meta_title Titre SEO
 * @property string|null $meta_description Description SEO
 * @property int $products_count Nombre de produits (calculé)
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 *
 * @property-read Category|null $parent Catégorie parente
 * @property-read \Illuminate\Database\Eloquent\Collection|Category[] $children Sous-catégories
 * @property-read \Illuminate\Database\Eloquent\Collection|Product[] $products Produits de la catégorie
 * @property-read string $full_path Chemin complet pour URL frontend
 * @property-read array $breadcrumbs Fil d'Ariane pour navigation
 */
class Category extends BaseModel
{
    use Searchable;

    /**
     * Champs indexés pour la recherche
     */
    protected $searchableFields = ['name', 'description'];

    /**
     * Champs assignables en masse
     */
    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'image',
        'icon',
        'color',
        'level',
        'path',
        'is_active',
        'is_visible_in_menu',
        'is_featured',
        'sort_order',
        'meta_data',
        'meta_title',
        'meta_description',
        'products_count',
    ];

    /**
     * Attributs à caster
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_visible_in_menu' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
            'level' => 'integer',
            'products_count' => 'integer',
            'meta_data' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Attributs ajoutés à la sérialisation
     */
    protected $appends = ['image_url', 'full_path'];

    // ============================================================================
    // RELATIONS
    // ============================================================================

    /**
     * Catégorie parente
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * Sous-catégories (enfants directs)
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * Tous les descendants récursifs (enfants, petits-enfants, etc.)
     */
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    /**
     * Produits appartenant à cette catégorie (Many-to-Many)
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'category_product',
            'category_id',
            'product_id'
        )
            ->withPivot('is_primary', 'sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    // ============================================================================
    // ACCESSEURS & MUTATEURS
    // ============================================================================

    /**
     * URL complète de l'image
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->image
                ? asset('storage/' . $this->image)
                : null
        );
    }

    /**
     * Chemin complet pour URL frontend (/categories/slug)
     */
    protected function fullPath(): Attribute
    {
        return Attribute::make(
            get: fn() => "/categories/{$this->slug}"
        );
    }

    /**
     * Fil d'Ariane (breadcrumbs) pour navigation
     */
    protected function breadcrumbs(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!$this->path) {
                    return [[
                        'name' => $this->name,
                        'slug' => $this->slug,
                        'path' => $this->full_path
                    ]];
                }

                try {
                    $ids = array_filter(explode('/', trim($this->path, '/')));

                    if (empty($ids)) {
                        return [[
                            'name' => $this->name,
                            'slug' => $this->slug,
                            'path' => $this->full_path
                        ]];
                    }

                    // 🔥 FIX: Utilise une approche plus simple sans array_position
                    $ancestors = Category::whereIn('id', $ids)
                        ->orderBy('level') // Tri par level au lieu d'array_position
                        ->get();

                    return $ancestors->map(fn($cat) => [
                        'name' => $cat->name,
                        'slug' => $cat->slug,
                        'path' => "/categories/{$cat->slug}"
                    ])->toArray();
                } catch (\Exception $e) {
                    // En cas d'erreur, retourne au moins la catégorie actuelle
                    Log::error('Breadcrumbs error: ' . $e->getMessage());
                    return [[
                        'name' => $this->name,
                        'slug' => $this->slug,
                        'path' => $this->full_path
                    ]];
                }
            }
        );
    }

    // ============================================================================
    // MÉTHODES MÉTIER - HIÉRARCHIE
    // ============================================================================

    /**
     * Calcule et met à jour le path hiérarchique
     * Format: /uuid1/uuid2/uuid3
     */
    public function calculatePath(): void
    {
        if (!$this->parent_id) {
            $this->path = "/{$this->id}";
            $this->level = 0;
            return;
        }

        $parent = $this->parent ?? Category::find($this->parent_id);

        if (!$parent) {
            $this->path = "/{$this->id}";
            $this->level = 0;
            return;
        }

        $this->path = "{$parent->path}/{$this->id}";
        $this->level = $parent->level + 1;
    }

    /**
     * Met à jour récursivement les paths des enfants
     */
    public function updateChildrenPaths(): void
    {
        $this->children()->each(function (Category $child) {
            $child->calculatePath();
            $child->saveQuietly(); // Évite de déclencher les events
            $child->updateChildrenPaths();
        });
    }

    /**
     * Récupère tous les ancêtres (parent, grand-parent, etc.)
     *
     * @return Collection<Category>
     */
    public function ancestors(): Collection
    {
        if (!$this->path) {
            return collect();
        }

        $ids = explode('/', trim($this->path, '/'));
        array_pop($ids); // Retire l'ID actuel

        if (empty($ids)) {
            return collect();
        }

        return Category::whereIn('id', $ids)
            ->orderByRaw("array_position(array['" . implode("','", $ids) . "']::uuid[], id::text)")
            ->get();
    }

    /**
     * Récupère tous les descendants (via le path)
     *
     * @return Collection<Category>
     */
    public function allDescendants(): Collection
    {
        return Category::where('path', 'like', "{$this->path}/%")->get();
    }

    /**
     * Récupère le chemin complet (fil d'Ariane texte)
     *
     * @param string $separator Séparateur entre les noms
     * @return string Ex: "Homme > Hauts > T-shirts"
     */
    public function getFullPathText(string $separator = ' > '): string
    {
        $ancestors = $this->ancestors();
        $path = $ancestors->pluck('name')->push($this->name);

        return $path->implode($separator);
    }

    /**
     * Récupère le slug complet (pour URL alternative si besoin)
     *
     * @return string Ex: "homme/hauts/tshirts"
     */
    public function getFullSlug(): string
    {
        $ancestors = $this->ancestors();
        $slugs = $ancestors->pluck('slug')->push($this->slug);

        return $slugs->implode('/');
    }

    // ============================================================================
    // MÉTHODES MÉTIER - VÉRIFICATIONS
    // ============================================================================

    /**
     * Vérifie si la catégorie est une racine (pas de parent)
     */
    public function isRoot(): bool
    {
        return is_null($this->parent_id) || $this->level === 0;
    }

    /**
     * Vérifie si la catégorie est une feuille (pas d'enfants)
     */
    public function isLeaf(): bool
    {
        return $this->children()->count() === 0;
    }

    /**
     * Vérifie si la catégorie peut avoir des produits
     * (généralement uniquement les feuilles ou niveau 2+)
     */
    public function canHaveProducts(): bool
    {
        // Pour la mode, seules les catégories de niveau 2+ peuvent avoir des produits
        return $this->level >= 2;
    }

    /**
     * Vérifie si une catégorie est descendante de cette catégorie
     */
    public function isAncestorOf(Category $category): bool
    {
        return $category->ancestors()->contains('id', $this->id);
    }

    /**
     * Vérifie si une catégorie est ancêtre de cette catégorie
     */
    public function isDescendantOf(Category $category): bool
    {
        return $this->ancestors()->contains('id', $category->id);
    }

    /**
     * Récupère le genre (catégorie racine)
     *
     * @return Category|null Ex: Homme, Femme, Enfant
     */
    public function getGenre(): ?Category
    {
        if ($this->isRoot()) {
            return $this;
        }

        $ancestors = $this->ancestors();
        return $ancestors->first(); // La première est toujours le genre
    }

    // ============================================================================
    // MÉTHODES MÉTIER - COMPTEURS
    // ============================================================================

    /**
     * Met à jour le compteur de produits
     */
    public function updateProductsCount(): void
    {
        $this->products_count = $this->products()->count();
        $this->saveQuietly(); // Sans déclencher les événements
    }

    /**
     * Récupère le nombre total de produits (incluant les sous-catégories)
     */
    public function getTotalProductsCount(): int
    {
        $count = $this->products_count;

        foreach ($this->children as $child) {
            $count += $child->getTotalProductsCount();
        }

        return $count;
    }

    // ============================================================================
    // SCOPES
    // ============================================================================

    /**
     * Catégories racines (sans parent)
     */
    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Catégories actives
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Catégories visibles dans le menu
     */
    public function scopeVisibleInMenu($query)
    {
        return $query->where('is_visible_in_menu', true);
    }

    /**
     * Catégories mises en avant
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * Catégories avec produits
     */
    public function scopeWithProducts($query)
    {
        return $query->has('products');
    }

    /**
     * Catégories d'un niveau spécifique
     *
     * @param int $level 0 = Genre, 1 = Type, 2 = Catégorie, etc.
     */
    public function scopeLevel($query, int $level)
    {
        return $query->where('level', $level);
    }

    /**
     * Catégories filles d'un parent
     */
    public function scopeChildrenOf($query, string $parentId)
    {
        return $query->where('parent_id', $parentId);
    }

    /**
     * Tri par ordre défini puis par nom
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Recherche par slug
     */
    public function scopeBySlug($query, string $slug)
    {
        return $query->where('slug', $slug);
    }

    // ============================================================================
    // ÉVÉNEMENTS DU MODÈLE
    // ============================================================================

    /**
     * Boot du modèle
     */
    protected static function boot(): void
    {
        parent::boot();

        // Génère automatiquement le path avant création
        static::creating(function (Category $category) {
            $category->calculatePath();
        });

        // Met à jour le path si le parent change
        static::updating(function (Category $category) {
            if ($category->isDirty('parent_id')) {
                $category->calculatePath();
            }
        });

        // Met à jour les enfants quand le path change
        static::updated(function (Category $category) {
            if ($category->wasChanged('path')) {
                $category->updateChildrenPaths();
            }
        });

        // Supprimer les enfants en cascade lors de la suppression définitive
        static::deleting(function (Category $category) {
            if ($category->isForceDeleting()) {
                // Supprimer récursivement tous les enfants
                foreach ($category->children as $child) {
                    $child->forceDelete();
                }
            } else {
                // Soft delete: détacher les produits
                $category->products()->detach();
            }
        });
    }
}
