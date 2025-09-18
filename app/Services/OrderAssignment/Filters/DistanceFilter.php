<?php

namespace App\Services\OrderAssignment\Filters;

use App\Services\MapboxDistanceService;
use App\Services\OrderAssignment\Logger\AssignmentLogger; 

class DistanceFilter
{
    protected $distanceService;

    public function __construct(MapboxDistanceService $service)
    {
        $this->distanceService = $service;
    }
    
    public function handle($candidates, $order)
    {
        return $this->filter($candidates, $order);
    }

    public function filter($candidates, $order)
    {
        AssignmentLogger::log("DistanceFilter: === INICIO DEBUG ===");
        AssignmentLogger::log("DistanceFilter: Candidatos recibidos: " . $candidates->count());
        AssignmentLogger::log("DistanceFilter: IDs recibidos: " . $candidates->pluck('id')->implode(', '));
        
        // EXTRAER COORDENADAS DEL JSON delivery_address
        $pickupCoords = $this->getDeliveryCoordinates($order);
        
        if (!$pickupCoords) {
            AssignmentLogger::log("DistanceFilter: ❌ ERROR - No se pudieron obtener coordenadas de delivery_address para orden {$order->id}");
            return collect();
        }

        AssignmentLogger::log("DistanceFilter: Orden ID: {$order->id}");
        AssignmentLogger::log("DistanceFilter: Delivery coords extraídas: [{$pickupCoords[0]}, {$pickupCoords[1]}]");

        if ($candidates->isEmpty()) {
            AssignmentLogger::log("DistanceFilter: No hay candidatos para procesar");
            return $candidates;
        }

        // Para cada candidato, calcula ETA/distancia
        $candidates->each(function ($dm) use ($pickupCoords) {
            AssignmentLogger::log("DistanceFilter: Procesando candidato ID: {$dm->id}");
            
            $currentLocation = $this->getDeliveryManLocation($dm);
            
            if (!$currentLocation) {
                AssignmentLogger::log("DistanceFilter: ❌ Candidato {$dm->id} SIN ubicación válida - asignando penalización");
                $dm->eta_to_pickup = PHP_INT_MAX;
                $dm->distance_to_pickup = PHP_INT_MAX;
                return;
            }

            AssignmentLogger::log("DistanceFilter: Candidato {$dm->id} ubicación: [{$currentLocation['longitude']}, {$currentLocation['latitude']}]");

            $coords = [[$currentLocation['longitude'], $currentLocation['latitude']]];
            
            if ($dm->activeHandoverOrder) {
                AssignmentLogger::log("DistanceFilter: Candidato {$dm->id} tiene orden activa - agregando parada");
                $activeOrderCoords = $this->getDeliveryCoordinates($dm->activeHandoverOrder);
                if ($activeOrderCoords) {
                    $coords[] = $activeOrderCoords;
                }
            }
            $coords[] = $pickupCoords;

            AssignmentLogger::log("DistanceFilter: Calculando ruta para candidato {$dm->id} con " . count($coords) . " puntos");

            try {
                $route = $this->distanceService->getRoute($coords);
                
                // Verificar si Mapbox falló y usar fallback
                if (($route['duration'] ?? PHP_INT_MAX) === PHP_INT_MAX) {
                    AssignmentLogger::log("DistanceFilter: Mapbox falló, usando cálculo simple para candidato {$dm->id}");
                    $simpleCalc = $this->calculateSimpleDistance($currentLocation, $pickupCoords);
                    $dm->eta_to_pickup = $simpleCalc['duration'];
                    $dm->distance_to_pickup = $simpleCalc['distance'];
                } else {
                    $dm->eta_to_pickup = $route['duration'];
                    $dm->distance_to_pickup = $route['distance'];
                }
                
                AssignmentLogger::log("DistanceFilter: Candidato {$dm->id} - ETA: {$dm->eta_to_pickup}s, Distancia: {$dm->distance_to_pickup}m");
                
            } catch (\Exception $e) {
                AssignmentLogger::log("DistanceFilter: ❌ ERROR calculando ruta para {$dm->id}: " . $e->getMessage());
                $simpleCalc = $this->calculateSimpleDistance($currentLocation, $pickupCoords);
                $dm->eta_to_pickup = $simpleCalc['duration'];
                $dm->distance_to_pickup = $simpleCalc['distance'];
            }
        });

        $validCandidates = $candidates->filter(function ($dm) {
            $isValid = $dm->eta_to_pickup !== PHP_INT_MAX;
            if (!$isValid) {
                AssignmentLogger::log("DistanceFilter: ❌ Eliminando candidato {$dm->id} por ubicación inválida");
            }
            return $isValid;
        });

        AssignmentLogger::log("DistanceFilter: Candidatos válidos después de filtrar ubicaciones: " . $validCandidates->count());

        if ($validCandidates->isEmpty()) {
            AssignmentLogger::log("DistanceFilter: ❌ NO QUEDAN CANDIDATOS VÁLIDOS después del filtro de ubicaciones");
            return $validCandidates;
        }

        $sorted = $validCandidates->sortBy([
            ['eta_to_pickup', 'asc'],
            ['distance_to_pickup', 'asc'],
        ])->values();

        AssignmentLogger::log("DistanceFilter: Candidatos ordenados por ETA:");
        $sorted->each(function ($dm, $index) {
            AssignmentLogger::log("  {$index}. ID {$dm->id}: ETA={$dm->eta_to_pickup}s, Dist={$dm->distance_to_pickup}m");
        });

        AssignmentLogger::log("DistanceFilter: === FIN DEBUG - Retornando " . $sorted->count() . " candidatos ===");
        
        return $sorted;
    }

    private function calculateSimpleDistance($currentLocation, $pickupCoords): array
    {
        $fromLat = $currentLocation['latitude'];
        $fromLng = $currentLocation['longitude'];
        $toLat = $pickupCoords[1];
        $toLng = $pickupCoords[0];
        
        $latDiff = ($toLat - $fromLat) * 111320;
        $lngDiff = ($toLng - $fromLng) * 111320 * cos(deg2rad($fromLat));
        $distance = sqrt($latDiff * $latDiff + $lngDiff * $lngDiff);
        
        $eta = ($distance / 30000) * 3600; // 30 km/h promedio
        
        AssignmentLogger::log("DistanceFilter: Cálculo simple - Distancia: {$distance}m, ETA: {$eta}s");
        
        return [
            'distance' => (int) $distance,
            'duration' => (int) $eta
        ];
    }

    private function getDeliveryCoordinates($order): ?array
    {
        try {
            if (is_array($order->delivery_address)) {
                $deliveryData = $order->delivery_address;
            } else {
                $deliveryData = json_decode($order->delivery_address, true);
            }

            if (!$deliveryData) {
                AssignmentLogger::log("DistanceFilter: ❌ delivery_address no es JSON válido para orden {$order->id}");
                return null;
            }

            $latitude = $deliveryData['latitude'] ?? null;
            $longitude = $deliveryData['longitude'] ?? null;

            if (is_string($latitude)) $latitude = (float) $latitude;
            if (is_string($longitude)) $longitude = (float) $longitude;

            if (!$latitude || !$longitude || $latitude == 0 || $longitude == 0) {
                AssignmentLogger::log("DistanceFilter: ❌ Coordenadas inválidas - lat: {$latitude}, lng: {$longitude}");
                return null;
            }

            return [$longitude, $latitude];

        } catch (\Exception $e) {
            AssignmentLogger::log("DistanceFilter: ❌ Error extrayendo coordenadas: " . $e->getMessage());
            return null;
        }
    }

    protected function getDeliveryManLocation($deliveryMan): ?array
    {
        AssignmentLogger::log("DistanceFilter: Obteniendo ubicación para repartidor {$deliveryMan->id}");
        
        if ($deliveryMan->current_orders > 0) {
            $realTimeLocation = $this->getAblyLocation($deliveryMan->id);
            if ($realTimeLocation) {
                return $realTimeLocation;
            }
        }
        
        if ($deliveryMan->last_location) {
            AssignmentLogger::log("DistanceFilter: Usando last_location para repartidor {$deliveryMan->id}");
            return [
                'latitude' => (float) $deliveryMan->last_location->latitude,
                'longitude' => (float) $deliveryMan->last_location->longitude,
                'updated_at' => $deliveryMan->last_location->time
            ];
        }
        
        if (isset($deliveryMan->latitude) && isset($deliveryMan->longitude) && 
            $deliveryMan->latitude && $deliveryMan->longitude) {
            AssignmentLogger::log("DistanceFilter: Usando coordenadas directas para repartidor {$deliveryMan->id}");
            return [
                'latitude' => (float) $deliveryMan->latitude,
                'longitude' => (float) $deliveryMan->longitude,
                'updated_at' => now()
            ];
        }
        
        AssignmentLogger::log("DistanceFilter: ❌ No se encontró ubicación para repartidor {$deliveryMan->id}");
        return null;
    }

    protected function getAblyLocation($deliveryManId): ?array
    {
        return null; // Por implementar
    }
}