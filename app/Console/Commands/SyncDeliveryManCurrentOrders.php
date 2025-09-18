<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\DeliveryMan;
use App\Models\Order;

class SyncDeliveryManCurrentOrders extends Command
{
    protected $signature = 'deliverymen:sync-current-orders {--dry-run : Solo mostrar diferencias sin actualizar}';
    protected $description = 'Sincroniza el campo current_orders con las órdenes reales';

    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        
        $this->info($isDryRun ? 'MODO DRY-RUN: Solo mostrando diferencias' : 'SINCRONIZANDO current_orders...');
        
        $deliveryMen = DeliveryMan::all();
        $inconsistencies = 0;
        $activeStatuses = ['pending', 'confirmed', 'processing', 'assigned', 'picked_up', 'out_for_delivery'];
        
        foreach($deliveryMen as $dm) {
            $realCount = Order::where(function($query) use ($dm) {
                $query->where('delivery_man_id', $dm->id)
                      ->orWhere('reserved_delivery_man_id', $dm->id);
            })
            ->whereIn('order_status', $activeStatuses)
            ->count();
            
            if ($dm->current_orders != $realCount) {
                $inconsistencies++;
                $this->warn("DM {$dm->id}: current_orders={$dm->current_orders} -> real_count={$realCount}");
                
                if (!$isDryRun) {
                    $dm->current_orders = $realCount;
                    $dm->save();
                    $this->info("  ✅ Actualizado");
                }
            }
        }
        
        if ($inconsistencies === 0) {
            $this->info('✅ Todos los repartidores están sincronizados');
        } else {
            $this->info("📊 Total inconsistencias: {$inconsistencies}");
            if ($isDryRun) {
                $this->info("💡 Ejecuta sin --dry-run para aplicar los cambios");
            }
        }
    }
}