<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMaxCashBalanceToDeliveryMenTable extends Migration
{
    public function up()
{
    Schema::table('delivery_men', function (Blueprint $table) {
        $table->decimal('max_cash_balance', 10, 2) ->comment('Límite máximo de efectivo individual para cada repartidor');
    });
}

    public function down()
    {
        Schema::table('delivery_men', function (Blueprint $table) {
            $table->dropColumn('max_cash_balance');
        });
    }
}