<?php

namespace App\Services;

use App\Models\Order;
use App\Models\DeliveryMan;
use App\Services\OrderAssignment\Logger\AssignmentLogger;
use Illuminate\Support\Collection;
use App\Services\OrderAssignment\Filters\DistanceFilter;
use App\Services\MapboxDistanceService;

class OrderAssignmentService
{
    protected array $filters = [];
    protected DistanceFilter $distanceFilter;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters ?: [
            new \App\Services\OrderAssignment\Filters\BasicFilters(),
            new \App\Services\OrderAssignment\Filters\BalanceFilter(),
            new \App\Services\OrderAssignment\Filters\WorkloadFilter(2),
        ];

        $candidates = DeliveryMan::with(['wallet', 'activeHandoverOrder', 'last_location'])->get();
        // Instancia el servicio de distancia y el filtro de distancia para Mapbox
        $distanceService = app(MapboxDistanceService::class);
        $this->distanceFilter = new DistanceFilter($distanceService);
    }

    public function assignOrderToBestCandidate(Order $order)
    {
        // 🛡️ VALIDACIÓN DE INTEGRIDAD
        $inconsistentCandidates = DeliveryMan::whereRaw('
            current_orders != (
                SELECT COUNT(*) FROM orders 
                WHERE (delivery_man_id = delivery_men.id OR reserved_delivery_man_id = delivery_men.id)
                AND order_status IN ("pending", "confirmed", "processing", "assigned", "picked_up", "out_for_delivery")
            )
        ')->count();
        
        if ($inconsistentCandidates > 0) {
            AssignmentLogger::log("[WARNING] {$inconsistentCandidates} repartidores con current_orders inconsistente. Ejecutar: php artisan deliverymen:sync-current-orders");
        }
        // Carga wallet y activeHandoverOrder juntos
        $candidates = DeliveryMan::with(['wallet', 'activeHandoverOrder'])->get();
        AssignmentLogger::log('Candidatos iniciales: ' . $candidates->pluck('id')->implode(', '));
        
        // 🔍 DEBUG ESPECÍFICO PARA ID 2
        $candidate2 = $candidates->where('id', 2)->first();
        if ($candidate2) {
        AssignmentLogger::log("[DEBUG] Candidato ID 2 encontrado - current_orders: " . json_encode($candidate2->current_orders) . " (tipo: " . gettype($candidate2->current_orders) . ")");
    
            // 🆕 NUEVO DEBUG
            AssignmentLogger::log("[DEBUG] Candidato ID 2 - activeHandoverOrder: " . ($candidate2->activeHandoverOrder ? 'SÍ tiene' : 'NO tiene'));
            if ($candidate2->activeHandoverOrder) {
                AssignmentLogger::log("[DEBUG] Candidato ID 2 - Handover ID: " . $candidate2->activeHandoverOrder->id);
            }
        }

        // DEBUG 1: Chequea current_orders apenas cargados
        foreach ($candidates as $dm) {
            if (!is_numeric($dm->current_orders)) {
                AssignmentLogger::log("[DEBUG] Inicia: current_orders de {$dm->id} es tipo: " . gettype($dm->current_orders) . " - Valor: " . json_encode($dm->current_orders));
            }
        }

        // Filtro rápido según la lógica de órdenes activas y handover
        $candidates = $candidates->filter(function ($deliveryman) {
            if ($deliveryman->current_orders === 0) return true;
            if ($deliveryman->current_orders === 1 && $deliveryman->activeHandoverOrder) return true;
            return false;
        })->values();

        AssignmentLogger::log('Candidatos tras filtro activo/handover: ' . $candidates->pluck('id')->implode(', '));

        foreach ($this->filters as $filter) {
            $candidates = $filter->handle($candidates, $order);

            // Protección extra: fuerza que siempre sea una colección
            if (!$candidates instanceof Collection) {
                AssignmentLogger::log(class_basename($filter) . " devolvió un valor no Colección. Se fuerza a colección vacía.");
                $candidates = collect();
            }

            AssignmentLogger::log(
                class_basename($filter) . ' aplicado. Candidatos restantes: ' . $candidates->pluck('id')->implode(', ')
            );
        }

        // Segunda protección por si acaso
        if (!$candidates instanceof Collection) {
            $candidates = collect();
        }

        if ($candidates->isEmpty()) {
            AssignmentLogger::log('No hay candidatos disponibles tras aplicar filtros.');
            return null;
        }

        // DEBUG 2: Chequea current_orders justo antes de seleccionar el mejor
        foreach ($candidates as $dm) {
            if (!is_numeric($dm->current_orders)) {
                AssignmentLogger::log("[DEBUG][PreSeleccion] current_orders de {$dm->id} es tipo " . gettype($dm->current_orders) . " - Valor: " . json_encode($dm->current_orders));
            }
        }
        
        foreach ($candidates as $dm) {
            if ($dm->current_orders instanceof \Illuminate\Support\Collection) {
                \Log::error("[FIX] current_orders era colección en DM {$dm->id}, se corrige a count.");
                $dm->current_orders = $dm->current_orders->count();
            }
        }

        foreach ($candidates as $dm) {
            if (!is_numeric($dm->current_orders)) {
                AssignmentLogger::log("[DEBUG][PreSeleccion] current_orders de {$dm->id} es tipo " . gettype($dm->current_orders) . " - Valor: " . json_encode($dm->current_orders));
            }
        }

        // ---- INTEGRA EL FILTRO DE DISTANCIA AQUÍ ----
        $candidates = $this->distanceFilter->filter($candidates, $order);

        if ($candidates->isEmpty()) {
            AssignmentLogger::log('No hay candidatos disponibles tras filtro de distancia.');
            return null;
        }

        // Selección por menor ETA/distancia (el primero ya será el mejor)
        $best = $candidates->first();
        AssignmentLogger::log('Asignado: ' . ($best ? $best->id : 'Ninguno'));
        
        if ($best) {
            try {
                // SOLO asignar el repartidor, 
                $order->reserved_delivery_man_id = $best->id;
                $order->save();
                
                AssignmentLogger::log("Orden {$order->id} asignada a repartidor {$best->id} (order_status permanece como 'pending')");
                
            } catch (\Exception $e) {
                AssignmentLogger::log("ERROR al asignar orden {$order->id}: " . $e->getMessage());
                return null;
            }
        }

        return $best;
    }
}