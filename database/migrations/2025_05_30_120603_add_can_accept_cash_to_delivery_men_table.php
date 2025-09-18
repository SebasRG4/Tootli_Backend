<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCanAcceptCashToDeliveryMenTable extends Migration
{
    public function up()
    {
        Schema::table('delivery_men', function (Blueprint $table) {
            $table->boolean('can_accept_cash')->default(false)->after('id');
        });
    }

    public function down()
    {
        Schema::table('delivery_men', function (Blueprint $table) {
            $table->dropColumn('can_accept_cash');
        });
    }
}