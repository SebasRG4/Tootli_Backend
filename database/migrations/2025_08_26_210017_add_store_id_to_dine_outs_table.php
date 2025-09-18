<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('dine_outs', function (Blueprint $table) {
            // Solo agrega la columna si NO existe
            if (!Schema::hasColumn('dine_outs', 'store_id')) {
                $table->unsignedBigInteger('store_id')->after('id')->nullable();
            }
            // Si quieres relación estricta, descomenta la siguiente línea:
            // $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::table('dine_outs', function (Blueprint $table) {
            // Si tienes foreign key, primero elimínala:
            // $table->dropForeign(['store_id']);
            if (Schema::hasColumn('dine_outs', 'store_id')) {
                $table->dropColumn('store_id');
            }
        });
    }
};