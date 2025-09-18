<?php

namespace App\Services\OrderAssignment\Filters;

use Illuminate\Support\Collection;
use App\Models\Order;
use App\Services\OrderAssignment\Logger\AssignmentLogger;

class BasicFilters implements OrderAssignmentFilterInterface
{
    private const ONLINE_STATUS = 1;
    private const ACTIVE_STATUS = 1;
    private const CASH_ON_DELIVERY = 'cash_on_delivery';

    public function handle(Collection $candidates, Order $order): Collection
    {
        $originalCount = $candidates->count();
        AssignmentLogger::log("BasicFilters: Candidatos recibidos: $originalCount (" . $candidates->pluck('id')->implode(', ') . ")");

        $this->logCandidatesDetails($candidates);

        $filtered = $candidates->filter(function ($deliveryman) use ($order) {
            return $this->isValidDeliveryman($deliveryman, $order);
        })->values();

        AssignmentLogger::log("BasicFilters: Candidatos restantes: " . $filtered->count() . " (" . $filtered->pluck('id')->implode(', ') . ")");
        return $filtered;
    }

    private function logCandidatesDetails(Collection $candidates): void
    {
        AssignmentLogger::log("BasicFilters: Detalle de candidatos iniciales:");
        $candidates->each(function ($deliveryman) {
            $blocked = $deliveryman->status ?? 'NULL';
            $online = $deliveryman->active ?? 'NULL';
            $cash = $deliveryman->can_accept_cash ?? 'NULL';
            
            AssignmentLogger::log(sprintf(
                "  ID %d: status=%s (1=no bloqueado, 0=bloqueado), active=%s (1=en línea, 0=desconectado), can_accept_cash=%s",
                $deliveryman->id,
                $blocked,
                $online,
                $cash
            ));
        });
    }

    private function isValidDeliveryman($deliveryman, Order $order): bool
    {
        if (!$this->isNotBlocked($deliveryman)) return false;
        if (!$this->isOnline($deliveryman)) return false;
        if (!$this->canHandlePaymentMethod($deliveryman, $order)) return false;

        return true;
    }

    private function isNotBlocked($deliveryman): bool
    {
        // Nuevo estándar: 1 = no bloqueado, 0 = bloqueado
        $status = $deliveryman->status ?? null;
        
        if ($status == 0) { // bloqueado
            $this->logRejection($deliveryman->id, "estar bloqueado (status = 0)");
            return false;
        }
        
        if ($status != 1) { // valor inválido
            $this->logRejection($deliveryman->id, "status inválido '{$status}' (debe ser 1 para no bloqueado)");
            return false;
        }
        
        return true;
    }

    private function isOnline($deliveryman): bool
    {
        $active = $deliveryman->active ?? null;
        
        // 1 = en línea, 0 = desconectado
        if ($active != 1) {
            $activeType = gettype($active);
            $this->logRejection($deliveryman->id, "no estar en línea (active = {$active}, tipo: {$activeType})");
            return false;
        }
        
        return true;
    }

    private function canHandlePaymentMethod($deliveryman, Order $order): bool
    {
        if ($order->payment_method === self::CASH_ON_DELIVERY) {
            $canAcceptCash = $deliveryman->can_accept_cash ?? false;
            
            if (!$canAcceptCash) {
                $this->logRejection($deliveryman->id, "no acepta efectivo en contraentrega");
                return false;
            }
        }
        
        return true;
    }

    private function logRejection(int $deliverymanId, string $reason): void
    {
        AssignmentLogger::log("BasicFilters: ❌ Eliminado {$deliverymanId} por {$reason}");
    }
}