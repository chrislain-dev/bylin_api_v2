<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            UserSeeder::class,
            // CustomerSeeder::class, // Creates customers + addresses + wishlists + devices
            CatalogueSeeder::class, // Creates products + brands + cats + attributes
            // StockMovementSeeder::class, // Initializes stock
            // PromotionSeeder::class, // Creates coupons
            // OrderSeeder::class, // Creates orders using customers and products
            // ReviewSeeder::class, // Creates reviews
            // CollectionSeeder::class,
        ]);
    }
}
