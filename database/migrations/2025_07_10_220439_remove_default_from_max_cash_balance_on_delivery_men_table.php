<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RemoveDefaultFromMaxCashBalanceOnDeliveryMenTable extends Migration
{
    public function up()
    {
        Schema::table('delivery_men', function (Blueprint $table) {
            $table->integer('max_cash_balance')->nullable()->default(null)->change();
        });
    }

    public function down()
    {
        Schema::table('delivery_men', function (Blueprint $table) {
            $table->integer('max_cash_balance')->default(500)->change();
        });
    }
}