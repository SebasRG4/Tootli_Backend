<?php

namespace App\Services\OrderAssignment\Filters;

use Illuminate\Support\Collection;
use App\Models\Order;

class ZoneExperienceFilter implements OrderAssignmentFilterInterface
{
    public function handle(Collection $candidates, Order $order): Collection
    {
        // Si el pedido no tiene zona o tipo, no filtrar nada
        if (!isset($order->zone, $order->type)) {
            return $candidates;
        }
        return $candidates->filter(function ($deliveryman) use ($order) {
            // Se asume que el repartidor tiene un arreglo 'zone_experience' y 'type_experience'
            $zoneOk = !isset($deliveryman->zone_experience) || in_array($order->zone, (array)$deliveryman->zone_experience);
            $typeOk = !isset($deliveryman->type_experience) || in_array($order->type, (array)$deliveryman->type_experience);
            return $zoneOk && $typeOk;
        })->values();
    }
}