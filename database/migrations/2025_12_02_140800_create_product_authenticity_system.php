<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations
     */
    public function up(): void
    {
        // Create product authenticity table for QR code verification
        Schema::create('product_authenticity_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id');
            $table->foreignUuid('collection_id')
                ->nullable()
                ->after('product_id')
                ->constrained('collections')
                ->nullOnDelete()
                ->comment('Lien vers la collection pour tracking');
            $table->string('qr_code', 100)->unique(); // Unique QR code
            $table->string('serial_number', 50)->unique()->nullable(); // Optional serial number
            $table->boolean('is_authentic')->default(true);
            $table->boolean('is_activated')->default(false); // Activated on first scan
            $table->integer('scan_count')->default(0);
            $table->timestamp('first_scanned_at')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->uuid('purchased_by')->nullable(); // Customer who bought it
            $table->uuid('order_id')->nullable(); // Associated order
            $table->json('scan_locations')->nullable(); // Store scan GPS locations if needed
            $table->text('notes')->nullable(); // Admin notes
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('purchased_by')->references('id')->on('customers')->onDelete('set null');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('set null');

            $table->index('collection_id');
            $table->index('qr_code');
            $table->index('serial_number');
            $table->index('product_id');
            $table->index('is_authentic');
        });

        // Create scan history for detailed tracking
        Schema::create('authenticity_scan_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('authenticity_code_id');
            $table->string('qr_code');
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->json('location')->nullable(); // GPS if available
            $table->uuid('scanned_by')->nullable(); // Customer ID if logged in
            $table->string('scan_result'); // authentic, fake, already_activated
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('authenticity_code_id')->references('id')->on('product_authenticity_codes')->onDelete('cascade');
            $table->foreign('scanned_by')->references('id')->on('customers')->onDelete('set null');
            $table->index('qr_code');
            $table->index('scan_result');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations
     */
    public function down(): void
    {
        Schema::dropIfExists('authenticity_scan_logs');
        Schema::dropIfExists('product_authenticity_codes');
    }
};
