<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubscriptionFeaturesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('subscription_features')->insert([
            [
                'name' => 'DineOut',
                'key' => 'dineout',
                'description' => 'Destaca en el mapa de Tootli, atrae a más clientes y ofrece una experiencia interactiva única.',
                'price' => 250.00, // Precio individual
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Tootli Direct',
                'key' => 'tootli_direct',
                'description' => 'Vende a través de tus propios canales y deja que la red de repartidores de Tootli se encargue de la entrega.',
                'price' => 350.00, // Precio individual
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Tootli Aliada',
                'key' => 'tootli_aliada',
                'description' => 'Accede a descuentos exclusivos en comisiones y otros servicios por ser un aliado estratégico.',
                'price' => 150.00, // Precio individual
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}