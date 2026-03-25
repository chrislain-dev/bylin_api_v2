<?php

namespace Database\Seeders;

use Illuminate\Support\Str;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Catalogue\Models\Brand;
use Modules\Catalogue\Models\Product;
use Modules\Catalogue\Models\Category;
use Modules\Catalogue\Models\Attribute;

class CatalogueSeeder extends Seeder
{
    private \Faker\Generator $faker;

    public function run(): void
    {
        $this->faker = \Faker\Factory::create('fr_FR');

        DB::transaction(function () {
            $this->command->info('🚀 Début du seeding du catalogue...');

            $brands = $this->createBrands();
            $this->command->info('✓ ' . $brands->count() . ' marques créées');

            $categories = $this->createCategories();
            $this->command->info('✓ ' . $categories->count() . ' catégories créées');

            $attributes = $this->createAttributes();
            $this->command->info('✓ ' . $attributes->count() . ' attributs créés');

            // $this->createProducts($brands, $categories, $attributes);
            // $this->command->info('✓ Produits créés avec succès');

            $this->command->info('✨ Seeding terminé !');
        });
    }

    private function createBrands(): Collection
    {
        $brandsData = [
            ['name' => 'Bylin', 'slug' => 'bylin', 'sort_order' => 1],
            ['name' => 'Nike', 'slug' => 'nike', 'sort_order' => 2],
            ['name' => 'Adidas', 'slug' => 'adidas', 'sort_order' => 3],
            ['name' => 'Zara', 'slug' => 'zara', 'sort_order' => 4],
            ['name' => 'H&M', 'slug' => 'hm', 'sort_order' => 5],
            ['name' => 'Gucci', 'slug' => 'gucci', 'sort_order' => 6],
            ['name' => 'Levi\'s', 'slug' => 'levis', 'sort_order' => 7],
        ];

        return collect($brandsData)->map(function ($data) {
            return Brand::create(array_merge($data, [
                'description' => $this->faker->sentence(10),
                'is_active' => true,
            ]));
        });
    }

    private function createCategories(): Collection
    {
        $categories = collect();

        $genres = [
            ['name' => 'Homme', 'icon' => 'mars', 'color' => '#3B82F6'],
            ['name' => 'Femme', 'icon' => 'venus', 'color' => '#EC4899'],
        ];

        foreach ($genres as $i => $g) {
            $this->command->info("  📁 {$g['name']}");

            $cat = $this->createCategory(array_merge($g, [
                'sort_order' => $i + 1,
                'is_featured' => true,
            ]));
            $categories->push($cat);

            $types = ['Hauts', 'Bas', 'Chaussures', 'Accessoires'];

            foreach ($types as $j => $typeName) {
                $this->command->info("    📂 {$typeName}");

                $subCat = $this->createCategory([
                    'name' => $typeName,
                    'parent_id' => $cat->id,
                    'sort_order' => $j + 1,
                    'slug' => Str::slug($cat->name . '-' . $typeName)
                ]);
                $categories->push($subCat);

                $subTypes = match ($typeName) {
                    'Hauts' => ['T-shirts', 'Pulls', 'Chemises', 'Vestes'],
                    'Bas' => ['Jeans', 'Pantalons', 'Shorts', 'Jupes'],
                    'Chaussures' => ['Baskets', 'Bottes', 'Sandales', 'Escarpins'],
                    'Accessoires' => ['Sacs', 'Ceintures', 'Chapeaux', 'Bijoux'],
                    default => []
                };

                foreach ($subTypes as $k => $subTypeName) {
                    $finalCat = $this->createCategory([
                        'name' => $subTypeName,
                        'parent_id' => $subCat->id,
                        'sort_order' => $k + 1,
                        'slug' => Str::slug($cat->name . '-' . $typeName . '-' . $subTypeName)
                    ]);
                    $categories->push($finalCat);
                }
            }
        }

        // Catégories spéciales (niveau 0, sans parent)
        $this->command->info('  ✨ Catégories spéciales');

        $specials = [
            ['name' => 'Boutique', 'icon' => 'store', 'color' => '#F59E0B', 'slug' => 'products'],
            ['name' => 'Collections Bylin', 'icon' => 'sparkles', 'color' => '#6366F1', 'slug' => 'collections'],
        ];

        foreach ($specials as $i => $special) {
            $categories->push($this->createCategory(array_merge($special, [
                'is_featured' => true,
                'sort_order' => 100 + $i
            ])));
        }

        return $categories;
    }

    private function createCategory(array $data): Category
    {
        if (!isset($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        // Créer d'abord la catégorie pour avoir son ID
        $category = Category::create(array_merge([
            'is_active' => true,
            'is_visible_in_menu' => true,
            'description' => "Découvrez notre sélection de {$data['name']}",
            'meta_title' => $data['name'] . ' - Bylin Style',
            'meta_description' => "Achetez les meilleurs {$data['name']} sur Bylin Style",
            'level' => 0, // Valeur par défaut temporaire
            'path' => '/', // Valeur par défaut temporaire
        ], $data));

        if ($category->parent_id) {
            $parent = Category::find($category->parent_id);
            if ($parent) {
                $category->level = $parent->level + 1;
                $category->path = "{$parent->path}/{$category->id}";
            } else {
                $category->level = 0;
                $category->path = "/{$category->id}";
            }
        } else {
            $category->level = 0;
            $category->path = "/{$category->id}";
        }

        // Sauvegarde sans trigger les events
        $category->saveQuietly();

        return $category->fresh();
    }

    private function createAttributes(): Collection
    {
        $attributes = collect();

        // Taille vêtements
        $sizeAttr = Attribute::create(['name' => 'Taille', 'code' => 'size', 'type' => 'select']);
        $sizeAttr->values()->createMany(
            collect(['XS', 'S', 'M', 'L', 'XL', 'XXL'])->map(fn($v, $k) => [
                'value' => $v,
                'label' => $v,
                'sort_order' => $k
            ])->toArray()
        );
        $attributes->push($sizeAttr);

        // Pointure chaussures
        $shoeAttr = Attribute::create(['name' => 'Pointure', 'code' => 'shoe_size', 'type' => 'select']);
        $shoeAttr->values()->createMany(
            collect(range(36, 45))->map(fn($v, $k) => [
                'value' => (string)$v,
                'label' => (string)$v,
                'sort_order' => $k
            ])->toArray()
        );
        $attributes->push($shoeAttr);

        // Couleurs
        $colorAttr = Attribute::create(['name' => 'Couleur', 'code' => 'color', 'type' => 'color']);
        $colors = [
            ['value' => 'noir', 'label' => 'Noir', 'code' => '#000000'],
            ['value' => 'blanc', 'label' => 'Blanc', 'code' => '#FFFFFF'],
            ['value' => 'bleu', 'label' => 'Bleu', 'code' => '#0000FF'],
            ['value' => 'rouge', 'label' => 'Rouge', 'code' => '#FF0000'],
            ['value' => 'vert', 'label' => 'Vert', 'code' => '#00FF00'],
            ['value' => 'gris', 'label' => 'Gris', 'code' => '#808080'],
        ];
        foreach ($colors as $k => $c) {
            $colorAttr->values()->create([
                'value' => $c['value'],
                'label' => $c['label'],
                'color_code' => $c['code'],
                'sort_order' => $k
            ]);
        }
        $attributes->push($colorAttr);

        return $attributes;
    }

    private function createProducts($brands, $categories, $attributes): void
    {
        $sizeAttribute = Attribute::with('values')->where('code', 'size')->first();
        $shoeAttribute = Attribute::with('values')->where('code', 'shoe_size')->first();
        $colorAttribute = Attribute::with('values')->where('code', 'color')->first();

        if (!$sizeAttribute || !$shoeAttribute || !$colorAttribute) {
            $this->command->error('❌ Erreur: Les attributs n\'ont pas été créés correctement');
            return;
        }

        if ($sizeAttribute->values->isEmpty() || $shoeAttribute->values->isEmpty() || $colorAttribute->values->isEmpty()) {
            $this->command->error('❌ Erreur: Les valeurs d\'attributs sont vides');
            return;
        }

        $this->command->info('📊 Distribution des catégories par niveau:');
        $categories->groupBy('level')->each(function ($cats, $level) {
            $this->command->info("  Niveau {$level}: {$cats->count()} catégories");
        });

        // Récupère uniquement les catégories feuilles (niveau 2)
        $leafCategories = $categories->filter(fn($c) => $c->level === 2);

        if ($leafCategories->isEmpty()) {
            $this->command->error('❌ Erreur: Aucune catégorie de niveau 2 trouvée');
            $this->command->warn('💡 Catégories disponibles:');
            $categories->take(10)->each(function ($cat) {
                $this->command->warn("  - {$cat->name} (level: {$cat->level}, parent_id: {$cat->parent_id})");
            });
            return;
        }

        $this->command->info("✓ {$leafCategories->count()} catégories de niveau 2 trouvées");

        $bylinBrand = $brands->firstWhere('slug', 'bylin');
        $totalProducts = 80;

        for ($i = 0; $i < $totalProducts; $i++) {
            $brand = ($i < 20 && $bylinBrand) ? $bylinBrand : $brands->random();
            $category = $leafCategories->random();

            // Construction de la hiérarchie de catégories
            $catsToSync = [$category->id];
            if ($category->parent_id) {
                $catsToSync[] = $category->parent_id;
                $parent = $categories->firstWhere('id', $category->parent_id);
                if ($parent && $parent->parent_id) {
                    $catsToSync[] = $parent->parent_id;
                }
            }

            // Détection du type de produit
            $isShoe = Str::contains(strtolower($category->slug), 'chaussure');
            $isAccessory = Str::contains(strtolower($category->slug), 'accessoire');
            $isClothing = !$isShoe && !$isAccessory;

            $name = $this->faker->words(2, true) . ' ' . $brand->name;

            $product = Product::create([
                'brand_id' => $brand->id,
                'name' => ucfirst($name),
                'slug' => Str::slug($name) . '-' . Str::random(6),
                'sku' => strtoupper(substr($brand->name, 0, 3)) . '-' . strtoupper(Str::random(8)),
                'description' => $this->faker->paragraph,
                'short_description' => $this->faker->sentence,
                'price' => $this->faker->numberBetween(20, 300) * 100,
                'stock_quantity' => 0,
                'status' => 'active',
                'is_featured' => $this->faker->boolean(20),
                'is_variable' => false,
                'requires_authenticity' => ($brand->slug === 'bylin'),
            ]);

            $product->categories()->sync($catsToSync);

            // Création des variations pour vêtements et chaussures
            if ($isClothing || $isShoe) {
                $targetSizeAttr = $isShoe ? $shoeAttribute : $sizeAttribute;

                $sizeCount = $targetSizeAttr->values->count();
                $colorCount = $colorAttribute->values->count();

                if ($sizeCount === 0 || $colorCount === 0) {
                    $this->command->warn("⚠️  Pas de valeurs d'attributs pour le produit {$product->name}");
                    continue;
                }

                $selectedSizes = $targetSizeAttr->values->random(min(3, $sizeCount));
                $selectedColors = $colorAttribute->values->random(min(2, $colorCount));

                foreach ($selectedColors as $colorVal) {
                    foreach ($selectedSizes as $sizeVal) {
                        $product->variations()->create([
                            'sku' => $product->sku . '-' . strtoupper($sizeVal->value) . '-' . strtoupper(substr($colorVal->value, 0, 3)),
                            'variation_name' => "{$sizeVal->label} / {$colorVal->label}",
                            'price' => $product->price,
                            'stock_quantity' => $this->faker->numberBetween(5, 20),
                            'is_active' => true,
                            'attributes' => [
                                $targetSizeAttr->code => $sizeVal->value,
                                $colorAttribute->code => $colorVal->value
                            ]
                        ]);
                    }
                }

                $product->update(['is_variable' => true]);
            } else {
                // Produit simple (accessoires)
                $product->update([
                    'stock_quantity' => $this->faker->numberBetween(10, 50),
                    'is_variable' => false
                ]);
            }

            if (($i + 1) % 10 === 0) {
                $this->command->info("  → " . ($i + 1) . "/{$totalProducts} produits créés");
            }
        }
    }
}
