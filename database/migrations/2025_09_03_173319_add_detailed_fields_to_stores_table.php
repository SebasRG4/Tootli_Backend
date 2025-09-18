<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->text('description')->default('Descripción no disponible')->after('name');
            $table->decimal('tootli_plus_discount', 5, 2)->nullable()->comment('Porcentaje de descuento para miembros Tootli+');
            $table->boolean('has_guarantee_seal')->default(false)->comment('Sello de garantía activado por el administrador');
            $table->boolean('serves_alcohol')->default(false);
            $table->string('average_cost_per_person', 100)->nullable()->comment('Ej: $150 - $300 por persona');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'tootli_plus_discount',
                'has_guarantee_seal',
                'serves_alcohol',
                'average_cost_per_person'
            ]);
        });
    }
};