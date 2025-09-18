<?php

namespace App\Services\OrderAssignment\Filters;

use Illuminate\Support\Collection;
use App\Models\Order;

class EquipmentFilter implements OrderAssignmentFilterInterface
{
    public function handle(Collection $candidates, Order $order): Collection
    {
        // Si el pedido no requiere equipamiento especial, no filtrar
        if (empty($order->required_equipment)) {
            return $candidates;
        }
        return $candidates->filter(function ($deliveryman) use ($order) {
            // Se asume que el repartidor tiene un arreglo 'equipment'
            return isset($deliveryman->equipment) &&
                is_array($deliveryman->equipment) &&
                in_array($order->required_equipment, $deliveryman->equipment);
        })->values();
    }
}