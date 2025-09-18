<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDeliverymanOfflinePaymentsTable extends Migration
{
    public function up()
    {
        Schema::create('deliveryman_offline_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('delivery_man_id');
            $table->unsignedBigInteger('offline_payment_method_id');
            $table->decimal('amount', 12, 2);
            $table->string('reference')->nullable();
            $table->string('evidence_path')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->foreign('delivery_man_id')->references('id')->on('delivery_men')->onDelete('cascade');
            $table->foreign('offline_payment_method_id')->references('id')->on('offline_payment_methods')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('deliveryman_offline_payments');
    }
}