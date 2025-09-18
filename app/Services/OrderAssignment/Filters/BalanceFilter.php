<?php

namespace App\Services\OrderAssignment\Filters;

use Illuminate\Support\Collection;
use App\Models\Order;
use App\Services\OrderAssignment\Logger\AssignmentLogger;

class BalanceFilter implements OrderAssignmentFilterInterface
{
    public function handle(Collection $candidates, Order $order): Collection
    {
        // Solo aplica para pago contraentrega
        if ($order->payment_method !== 'cash_on_delivery') {
            AssignmentLogger::log("BalanceFilter: No aplica (pago no es contraentrega). Candidatos: " . $candidates->pluck('id')->implode(', '));
            return $candidates;
        }

        $filtered = $candidates->filter(function ($deliveryman) {
            $collectedCash = $deliveryman->collected_cash; // Accesor en el modelo Deliveryman
            $maxCash = $deliveryman->max_cash_balance ?? null;
            $hasData = isset($collectedCash, $maxCash);
            $result = $hasData && $collectedCash < $maxCash;

            if (!$hasData) {
                AssignmentLogger::log("BalanceFilter: Eliminado {$deliveryman->id} por falta de datos de saldo o límite.");
            } elseif (!$result) {
                AssignmentLogger::log("BalanceFilter: Eliminado {$deliveryman->id} por collected_cash = {$collectedCash} >= max_cash_balance = {$maxCash}");
            }
            return $result;
        })->values();

        AssignmentLogger::log("BalanceFilter: Candidatos restantes: " . $filtered->pluck('id')->implode(', '));
        return $filtered;
    }
}