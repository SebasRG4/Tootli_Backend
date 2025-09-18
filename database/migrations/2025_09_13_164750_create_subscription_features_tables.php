<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Tabla para las funciones individuales (DineOut, Tootli Direct, etc.)
        Schema::create('subscription_features', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key')->unique(); // Una clave única como 'dineout', 'tootli_direct'
            $table->text('description');
            $table->decimal('price', 8, 2);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        // Tabla pivote para registrar las suscripciones de cada tienda a cada función
        Schema::create('store_subscription_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->foreignId('subscription_feature_id')->constrained()->onDelete('cascade');
            $table->timestamp('expires_at'); // Fecha de vencimiento para esta función específica
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_subscription_features');
        Schema::dropIfExists('subscription_features');
    }
};