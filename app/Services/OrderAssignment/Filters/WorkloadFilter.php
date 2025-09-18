<?php

namespace App\Services\OrderAssignment\Filters;

use Illuminate\Support\Collection;
use App\Models\Order;
use App\Services\OrderAssignment\Logger\AssignmentLogger;

/**
 * Filtro de Carga de Trabajo - Versión para sistema sin estado 'reserved'
 * 
 * En este sistema:
 * - order_status NO cambia a 'reserved' al asignar
 * - La asignación se marca SOLO con reserved_delivery_man_id
 * - Órdenes pendientes de confirmación tienen: order_status='pending' + reserved_delivery_man_id
 * 
 * LÓGICA ADAPTADA:
 * - current_orders: Órdenes confirmadas (desde delivery_men.current_orders)
 * - reserved_orders: Órdenes con reserved_delivery_man_id pero status='pending'
 * - Total máximo: current_orders + reserved_orders < límite
 * 
 * @author SebasRG4
 * @created 2025-07-18
 * @updated 2025-07-18 - Adaptado para sistema sin estado reserved
 */
class WorkloadFilter implements OrderAssignmentFilterInterface
{
    /** Máximo número de órdenes totales (activas + reservadas) */
    protected int $maxTotalOrders;

    /**
     * Constructor
     * 
     * @param int $maxTotalOrders Máximo de órdenes totales (default: 2)
     */
    public function __construct(int $maxTotalOrders = 2)
    {
        $this->maxTotalOrders = $maxTotalOrders;
    }

    /**
     * Aplica el filtro de carga de trabajo
     * 
     * LÓGICA ESPECÍFICA PARA TU SISTEMA:
     * - Cuenta órdenes con reserved_delivery_man_id Y order_status='pending'
     * - Estas son órdenes "reservadas" esperando confirmación del repartidor
     * 
     * @param Collection $candidates Candidatos del filtro anterior
     * @param Order $order Orden a asignar
     * @return Collection Candidatos que tienen capacidad disponible
     */
    public function handle(Collection $candidates, Order $order): Collection
    {
        $originalCount = $candidates->count();
        AssignmentLogger::log("WorkloadFilter: Candidatos recibidos: {$originalCount} (máximo total: {$this->maxTotalOrders})");
        AssignmentLogger::log("WorkloadFilter: Buscando órdenes con reserved_delivery_man_id + order_status='pending'");

        if ($candidates->isEmpty()) {
            return $candidates;
        }

        // Obtener órdenes "reservadas" (pending + assigned to deliveryman)
        $reservedOrdersCounts = $this->getReservedOrdersCounts($candidates->pluck('id'));

        // Log detallado del estado inicial
        $this->logCandidatesWorkload($candidates, $reservedOrdersCounts);

        // Aplicar filtro de capacidad
        $filtered = $candidates->filter(function ($deliveryman) use ($reservedOrdersCounts) {
            return $this->hasAvailableCapacity($deliveryman, $reservedOrdersCounts);
        })->values();

        $filteredCount = $filtered->count();
        AssignmentLogger::log("WorkloadFilter: Candidatos restantes: {$filteredCount} (" . $filtered->pluck('id')->implode(', ') . ")");

        return $filtered;
    }

    /**
     * Obtiene órdenes "reservadas" pero aún pendientes
     * 
     * CRITERIO ESPECÍFICO PARA TU SISTEMA:
     * - reserved_delivery_man_id IS NOT NULL (tiene repartidor asignado)
     * - order_status = 'pending' (aún no confirmada/entregada)
     * 
     * Esto identifica órdenes que están "esperando confirmación del repartidor"
     * 
     * @param Collection $deliverymanIds
     * @return array Array asociativo [deliveryman_id => count]
     */
    private function getReservedOrdersCounts(Collection $deliverymanIds): array
    {
        if ($deliverymanIds->isEmpty()) {
            return [];
        }

        try {
            $reservedCounts = Order::select('reserved_delivery_man_id')
                ->selectRaw('COUNT(*) as reserved_count')
                ->whereIn('reserved_delivery_man_id', $deliverymanIds->toArray())
                ->where('order_status', 'pending') // ✅ Solo órdenes pendientes
                ->whereNotNull('reserved_delivery_man_id') // ✅ Que tengan repartidor asignado
                ->groupBy('reserved_delivery_man_id')
                ->pluck('reserved_count', 'reserved_delivery_man_id')
                ->toArray();

            AssignmentLogger::log("WorkloadFilter: Órdenes pendientes asignadas obtenidas para " . count($reservedCounts) . " repartidores");
            
            // Log detallado para debugging
            if (!empty($reservedCounts)) {
                foreach ($reservedCounts as $dmId => $count) {
                    AssignmentLogger::log("  → Repartidor {$dmId}: {$count} órdenes pending+asignadas");
                }
            }
            
            return $reservedCounts;
            
        } catch (\Exception $e) {
            AssignmentLogger::log("WorkloadFilter: ERROR al obtener órdenes reservadas: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Log detallado de la carga de trabajo
     */
    private function logCandidatesWorkload(Collection $candidates, array $reservedOrdersCounts): void
    {
        AssignmentLogger::log("WorkloadFilter: Detalle de carga de trabajo:");
        $candidates->each(function ($deliveryman) use ($reservedOrdersCounts) {
            $currentOrders = $this->getCurrentOrders($deliveryman);
            $reservedOrders = $reservedOrdersCounts[$deliveryman->id] ?? 0;
            $totalOrders = $currentOrders + $reservedOrders;
            $canReceive = $totalOrders < $this->maxTotalOrders ? 'SÍ' : 'NO';
            
            AssignmentLogger::log("  ID {$deliveryman->id}: activas={$currentOrders}, pending_asignadas={$reservedOrders}, total={$totalOrders}, puede_recibir={$canReceive}");
        });
    }

    /**
     * Verifica capacidad disponible
     */
    private function hasAvailableCapacity($deliveryman, array $reservedOrdersCounts): bool
    {
        $currentOrders = $this->getCurrentOrders($deliveryman);
        $reservedOrders = $reservedOrdersCounts[$deliveryman->id] ?? 0;
        $totalOrders = $currentOrders + $reservedOrders;
        
        $hasCapacity = $totalOrders < $this->maxTotalOrders;
        
        if (!$hasCapacity) {
            AssignmentLogger::log("WorkloadFilter: ❌ Eliminado {$deliveryman->id} por sobrecarga total ({$currentOrders}+{$reservedOrders}={$totalOrders}/{$this->maxTotalOrders})");
        } else {
            AssignmentLogger::log("WorkloadFilter: ✅ Candidato {$deliveryman->id} disponible ({$currentOrders}+{$reservedOrders}={$totalOrders}/{$this->maxTotalOrders})");
        }
        
        return $hasCapacity;
    }

    /**
     * Obtiene current_orders de forma segura
     */
    private function getCurrentOrders($deliveryman): int
    {
        $rawValue = $deliveryman->current_orders ?? 0;
        return is_numeric($rawValue) ? (int)$rawValue : 0;
    }

    public function getMaxTotalOrders(): int
    {
        return $this->maxTotalOrders;
    }
}