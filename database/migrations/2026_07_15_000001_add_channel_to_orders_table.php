<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cahier des charges §9 — Finalisation de commande.
 * Chaque commande possède un canal : "online" (payée sur le site)
 * ou "whatsapp" (finalisée via un conseiller WhatsApp).
 * Cette information est visible dans le back-office.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('channel', 20)->default('online')->after('payment_method');
            $table->index('channel');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['channel']);
            $table->dropColumn('channel');
        });
    }
};
