<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Modules\Catalogue\Models\Product;
use Modules\Catalogue\Models\Category;

class HomeContentController extends ApiController
{
    /**
     * Get all home page content in one optimized request
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            // Cache for 5 minutes to reduce DB load
            $content = Cache::remember('home_content', 300, function () {
                return [
                    'latest_products' => $this->getLatestProducts(),
                    'featured_categories' => $this->getFeaturedCategories(),
                    'best_offer' => $this->getBestOffer(),
                    'cached_at' => now()->toIso8601String(),
                ];
            });

            return $this->successResponse(
                $content,
                'Home content retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve home content: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get latest products (4 items with badge logic)
     *
     * @return array
     */
    private function getLatestProducts(): array
    {
        try {
            $products = Product::with(['media', 'brand'])
                ->active()
                ->latest()
                ->take(8) // On prend plus pour pouvoir filtrer
                ->get();

            return $products->map(function ($product) {
                // Logique pour déterminer le badge
                $badge = null;

                // Nouveau: produits créés il y a moins de 30 jours
                if ($product->created_at->gt(now()->subDays(30))) {
                    $badge = 'Nouveau';
                }
                // Tendance: produits avec beaucoup de vues récentes
                elseif ($product->views_count > 100) {
                    $badge = 'Tendance';
                }
                // Populaire: produits avec bon rating
                elseif ($product->rating_average >= 4.5 && $product->rating_count >= 10) {
                    $badge = 'Populaire';
                }
                // En promo
                elseif ($product->is_on_sale && $product->compare_price) {
                    $badge = 'Promo';
                }

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'price' => $product->price,
                    'compare_price' => $product->compare_price,
                    'image_url' => $product->getFirstMediaUrl('images') ?: null,
                    'badge' => $badge,
                    'is_new' => $product->is_new,
                    'is_on_sale' => $product->is_on_sale,
                    'discount_percentage' => $product->discount_percentage,
                ];
            })
                ->take(4) // On garde les 4 meilleurs
                ->values()
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Failed to get latest products: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get featured categories (4 items)
     *
     * @return array
     */
    private function getFeaturedCategories(): array
    {
        try {
            return Category::query()
                ->featured()
                ->active()
                ->withCount('products')
                ->orderBy('sort_order')
                ->take(4)
                ->get()
                ->map(fn($category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'products_count' => $category->products_count,
                    'image_url' => $category->image_url,
                    'link' => "/categories/{$category->slug}",
                ])
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Failed to get featured categories: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get best promotional offer
     *
     * @return array|null
     */
    private function getBestOffer(): ?array
    {
        try {
            // Cherche le produit avec la meilleure réduction
            $product = Product::with(['media', 'brand'])
                ->active()
                ->where('is_on_sale', true)
                ->whereNotNull('compare_price')
                ->where('compare_price', '>', 0)
                ->get()
                ->filter(function ($p) {
                    return $p->compare_price > $p->price;
                })
                ->sortByDesc(function ($p) {
                    return (($p->compare_price - $p->price) / $p->compare_price) * 100;
                })
                ->first();

            if (!$product) {
                return null;
            }

            $discountPercentage = round((($product->compare_price - $product->price) / $product->compare_price) * 100);

            // Génère des features dynamiques basées sur le produit
            $features = [];

            if ($product->meta_data && isset($product->meta_data['material'])) {
                $features[] = $product->meta_data['material'];
            } else {
                $features[] = 'Tissu 100% coton';
            }

            $features[] = 'Tailles disponibles: S à XXL';
            $features[] = 'Livraison gratuite';
            $features[] = 'Retours sous 14 jours';

            return [
                'id' => $product->id,
                'product_id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'original_price' => $product->compare_price,
                'discount_price' => $product->price,
                'discount_percentage' => $discountPercentage,
                'image_url' => $product->getFirstMediaUrl('images') ?: null,
                'description' => $product->short_description ?? $product->description,
                'features' => $features,
                'end_date' => now()->addDays(3)->toIso8601String(), // Offre valable 3 jours
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get best offer: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Clear home content cache
     * (Useful for admin operations)
     *
     * @return JsonResponse
     */
    public function clearCache(): JsonResponse
    {
        try {
            Cache::forget('home_content');

            return $this->successResponse(
                null,
                'Home content cache cleared successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to clear cache: ' . $e->getMessage(),
                500
            );
        }
    }
}
