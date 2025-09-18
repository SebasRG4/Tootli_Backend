<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\OrderAssignmentService;
use App\Models\Order;

class AssignOrderCommand extends Command
{
    protected $signature = 'order:assign {order_id}';
    protected $description = 'Asignar una orden específica a un repartidor';

    public function handle()
    {
        $orderId = $this->argument('order_id');
        
        $order = Order::find($orderId);
        if (!$order) {
            $this->error("Orden {$orderId} no encontrada");
            return 1;
        }

        $this->info("Iniciando asignación para orden {$orderId}...");
        
        try {
            $assignmentService = app(OrderAssignmentService::class);
            $result = $assignmentService->assignOrder($order);
            
            if ($result) {
                $this->info("✅ Orden {$orderId} asignada exitosamente");
            } else {
                $this->error("❌ No se pudo asignar la orden {$orderId}");
            }
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}