<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\OrderReference;
use App\Models\DeliveryMan;

class OrderObserver
{
    private array $activeStatuses = ['pending', 'confirmed', 'processing', 'assigned', 'picked_up', 'out_for_delivery'];

    /**
     * Handle the Order "created" event.
     */
    public function created(Order $order): void
    {
        // 🔄 TU LÓGICA EXISTENTE (mantener tal como está)
        $OrderReference = new OrderReference();
        $OrderReference->order_id = $order->id;
        $OrderReference->save();
        
        // 🆕 NUEVA FUNCIONALIDAD: Actualizar current_orders
        $this->updateDeliveryManCurrentOrders($order);
    }

    /**
     * Handle the Order "updated" event.
     */
    public function updated(Order $order): void
    {
        // 🔄 TU LÓGICA EXISTENTE (mantener tal como está)
        if ($order->wasChanged('order_status') && $order->order_status === 'delivered') {
            $deliveryMan = $order->delivery_man;
            if ($deliveryMan) {
                $deliveryMan->max_cash_balance = $deliveryMan->updateCashLimit();
                $deliveryMan->checkAndActivateCashAcceptance();
                $deliveryMan->save();
            }
        }
        
        // 🆕 NUEVA FUNCIONALIDAD: Sincronizar current_orders cuando cambian asignaciones
        $this->handleDeliveryManChanges($order);
        $this->updateDeliveryManCurrentOrders($order);
    }

    /**
     * Handle the Order "deleted" event.
     */
    public function deleted(Order $order): void
    {
        // 🆕 NUEVA FUNCIONALIDAD: Actualizar current_orders al eliminar orden
        $this->updateDeliveryManCurrentOrders($order);
    }

    /**
     * Handle the Order "restored" event.
     */
    public function restored(Order $order): void
    {
        // 🆕 NUEVA FUNCIONALIDAD: Actualizar current_orders al restaurar orden
        $this->updateDeliveryManCurrentOrders($order);
    }

    /**
     * Handle the Order "force deleted" event.
     */
    public function forceDeleted(Order $order): void
    {
        // 🆕 NUEVA FUNCIONALIDAD: Actualizar current_orders al eliminar permanentemente
        $this->updateDeliveryManCurrentOrders($order);
    }
    
    // 🆕 MÉTODOS NUEVOS PARA GESTIONAR current_orders
    
    /**
     * Maneja cambios en delivery_man_id y reserved_delivery_man_id
     */
    private function handleDeliveryManChanges(Order $order)
    {
        // Si cambió delivery_man_id, actualizar el repartidor anterior
        if ($order->isDirty('delivery_man_id')) {
            $oldDeliveryManId = $order->getOriginal('delivery_man_id');
            if ($oldDeliveryManId) {
                $this->recalculateCurrentOrders($oldDeliveryManId);
            }
        }
        
        // Si cambió reserved_delivery_man_id, actualizar el repartidor anterior
        if ($order->isDirty('reserved_delivery_man_id')) {
            $oldReservedId = $order->getOriginal('reserved_delivery_man_id');
            if ($oldReservedId) {
                $this->recalculateCurrentOrders($oldReservedId);
            }
        }
    }
    
    /**
     * Actualiza current_orders de los repartidores asociados a la orden
     */
    private function updateDeliveryManCurrentOrders(Order $order)
    {
        if ($order->delivery_man_id) {
            $this->recalculateCurrentOrders($order->delivery_man_id);
        }
        
        if ($order->reserved_delivery_man_id) {
            $this->recalculateCurrentOrders($order->reserved_delivery_man_id);
        }
    }
    
    /**
     * Recalcula y actualiza el current_orders de un repartidor específico
     */
    private function recalculateCurrentOrders(int $deliveryManId)
    {
        try {
            $count = Order::where(function($query) use ($deliveryManId) {
                $query->where('delivery_man_id', $deliveryManId)
                      ->orWhere('reserved_delivery_man_id', $deliveryManId);
            })
            ->whereIn('order_status', $this->activeStatuses)
            ->count();
            
            $updated = DeliveryMan::where('id', $deliveryManId)->update(['current_orders' => $count]);
            
            if ($updated) {
                \Log::info("[OrderObserver] DeliveryMan {$deliveryManId} current_orders sincronizado a {$count}");
            }
            
        } catch (\Exception $e) {
            \Log::error("[OrderObserver] Error sincronizando current_orders para DeliveryMan {$deliveryManId}: " . $e->getMessage());
        }
    }
}