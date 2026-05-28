<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | BRANDS
        |--------------------------------------------------------------------------
        */
        Schema::create('brands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->boolean('is_bylin_brand')
                ->default(false)
                ->comment('Identifie si c\'est la marque Bylin (codes authenticité requis)');
            $table->text('description')->nullable();
            $table->string('website')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('meta_data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('slug');
            $table->index('is_bylin_brand');
            $table->index('is_active');
        });

        /*
        |--------------------------------------------------------------------------
        | COLLECTIONS
        |--------------------------------------------------------------------------
        */
        Schema::create('collections', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Basic info
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // Collection metadata
            $table->string('season')->nullable()->comment('Ex: Printemps 2024, Hiver 2025');
            $table->string('theme')->nullable()->comment('Ex: Urban, Streetwear, Classic');
            $table->date('release_date')->nullable();
            $table->date('end_date')->nullable()->comment('Date de fin de disponibilité');

            // Status & visibility
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);

            // Stats
            $table->integer('products_count')->default(0);
            $table->integer('total_stock')->default(0);

            // SEO
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->json('meta_keywords')->nullable();

            // Extra data
            $table->json('meta_data')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('slug');
            $table->index('is_active');
            $table->index('is_featured');
            $table->index('release_date');
            $table->index('sort_order');
        });

        /*
        |--------------------------------------------------------------------------
        | CATEGORIES
        |--------------------------------------------------------------------------
        */
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('parent_id')->nullable();

            $table->string('name', 100);
            $table->string('slug', 150)->unique();
            $table->text('description')->nullable();

            $table->string('image')->nullable();
            $table->string('icon', 50)->nullable();
            $table->string('color', 7)->nullable();

            $table->tinyInteger('level')->default(0);
            $table->string('path', 500)->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_visible_in_menu')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);

            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();

            $table->integer('products_count')->default(0);

            $table->json('meta_data')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
            $table->index('level');
            $table->index('path');
            $table->index('is_active');
            $table->index('sort_order');
            $table->index('deleted_at');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->foreign('parent_id')
                ->references('id')
                ->on('categories')
                ->nullOnDelete();
        });

        /*
        |--------------------------------------------------------------------------
        | PRODUCTS
        |--------------------------------------------------------------------------
        */
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('brand_id')->nullable();
            $table->foreignUuid('collection_id')
                ->nullable()
                ->after('brand_id')
                ->constrained('collections')
                ->nullOnDelete()
                ->comment('Collection Bylin (uniquement pour produits Bylin)');

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku')->unique();

            $table->text('short_description')->nullable();
            $table->text('description')->nullable();

            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('compare_price')->nullable();
            $table->unsignedBigInteger('cost_price')->nullable();

            $table->string('status')->default('draft');
            $table->boolean('is_preorder_enabled')->default(false);
            $table->boolean('requires_authenticity')->default(false);
            $table->integer('authenticity_codes_count')->default(0);
            $table->boolean('preorder_auto_enabled')->default(false);
            $table->dateTime('preorder_available_date')->nullable();
            $table->integer('preorder_limit')->nullable();
            $table->integer('preorder_count')->default(0);
            $table->text('preorder_message')->nullable();
            $table->text('preorder_terms')->nullable();
            $table->dateTime('preorder_enabled_at')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_new')->default(false);
            $table->boolean('is_on_sale')->default(false);
            $table->boolean('is_variable')->default(false);
            $table->boolean('track_inventory')->default(true);
            $table->integer('stock_quantity')->default(0);
            $table->integer('low_stock_threshold')->default(10);

            $table->string('barcode')->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->json('dimensions')->nullable();

            $table->json('meta_data')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('meta_keywords')->nullable();

            $table->integer('views_count')->default(0);
            $table->decimal('rating_average', 3, 2)->default(0);
            $table->integer('rating_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('brand_id')
                ->references('id')
                ->on('brands')
                ->nullOnDelete();

            $table->index('slug');
            $table->index('sku');
            $table->index('status');
            $table->index('is_preorder_enabled');
            $table->index(['is_preorder_enabled', 'preorder_available_date']);
            $table->index('requires_authenticity');
            $table->index('is_featured');
            $table->index('brand_id');
            $table->index('collection_id');
            $table->index(['brand_id', 'collection_id']);
            $table->index('price');
        });

        /*
        |--------------------------------------------------------------------------
        | CATEGORY_PRODUCT (pivot)
        |--------------------------------------------------------------------------
        */
        Schema::create('category_product', function (Blueprint $table) {
            $table->uuid('category_id');
            $table->uuid('product_id');
            $table->timestamps();

            $table->primary(['category_id', 'product_id']);

            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->cascadeOnDelete();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();
        });

        /*
        |--------------------------------------------------------------------------
        | PRODUCT_ATTRIBUTES (pivot)
        |--------------------------------------------------------------------------
        */
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->uuid('product_id');
            $table->uuid('attribute_id');
            $table->uuid('attribute_value_id');
            $table->timestamps();

            $table->primary(
                ['product_id', 'attribute_id', 'attribute_value_id'],
                'product_attributes_primary'
            );

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('attribute_id')->references('id')->on('attributes')->cascadeOnDelete();
            $table->foreign('attribute_value_id')->references('id')->on('attribute_values')->cascadeOnDelete();
        });

        /*
        |--------------------------------------------------------------------------
        | PRODUCT_VARIATIONS
        |--------------------------------------------------------------------------
        */
        Schema::create('product_variations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id');

            $table->string('sku')->unique();
            $table->string('variation_name');
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('compare_price')->nullable();
            $table->unsignedBigInteger('cost_price')->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->string('stock_status')->default('in_stock');
            $table->string('barcode')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('attributes');

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();

            $table->index('product_id');
            $table->index('sku');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variations');
        Schema::dropIfExists('product_attributes');
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('collections');
        Schema::dropIfExists('brands');
    }
};
