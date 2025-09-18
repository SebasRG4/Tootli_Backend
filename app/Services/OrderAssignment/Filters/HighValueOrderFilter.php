<?php

namespace App\Services\OrderAssignment\Filters;

use Illuminate\Support\Collection;
use App\Models\Order;

class HighValueOrderFilter implements OrderAssignmentFilterInterface
{
    protected float $minHighValue;

    public function __construct(float $minHighValue = 1000)
    {
        $this->minHighValue = $minHighValue;
    }

    public function handle(Collection $candidates, Order $order): Collection
    {
        // Si el pedido no es de alto valor, no filtrar nada
        if (!isset($order->total_amount) || $order->total_amount < $this->minHighValue) {
            return $candidates;
        }
        // Solo deja repartidores que pueden manejar pedidos de alto valor
        return $candidates->filter(function ($deliveryman) {
            // Se asume que hay un campo 'can_handle_high_value_orders'
            return !empty($deliveryman->can_handle_high_value_orders);
        })->values();
    }
}