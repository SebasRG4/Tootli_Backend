<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Services\OrderAssignment\Logger\AssignmentLogger;

class MapboxDistanceService
{
    protected $accessToken;

    public function __construct()
    {
        $this->accessToken = config('services.mapbox.token');
    }

    /**
     * Obtiene la duración y distancia de una ruta usando Mapbox Directions API.
     * @param array $coordinates Array de arrays: [[lon, lat], [lon, lat], ...]
     * @return array ['duration' => int (segundos), 'distance' => int (metros)]
     */
    public function getRoute($coords)
    {
        AssignmentLogger::log("MapboxService: === INICIO getRoute ===");
        AssignmentLogger::log("MapboxService: Coordenadas recibidas: " . json_encode($coords));
        
        try {
            // Validar que tenemos al menos 2 coordenadas
            if (count($coords) < 2) {
                throw new \Exception("Se necesitan al menos 2 coordenadas para calcular una ruta");
            }

            // Construir URL
            $url = $this->buildRouteUrl($coords);
            AssignmentLogger::log("MapboxService: URL construida: " . $url);
            
            // Hacer llamada HTTP
            $response = $this->makeHttpRequest($url);
            AssignmentLogger::log("MapboxService: Respuesta HTTP status: " . ($response['status'] ?? 'unknown'));
            AssignmentLogger::log("MapboxService: Respuesta HTTP body: " . json_encode($response['body'] ?? 'empty'));
            
            // Procesar respuesta
            $result = $this->processMapboxResponse($response);
            AssignmentLogger::log("MapboxService: Resultado procesado: " . json_encode($result));
            
            return $result;
            
        } catch (\Exception $e) {
            AssignmentLogger::log("MapboxService: ❌ ERROR - " . $e->getMessage());
            AssignmentLogger::log("MapboxService: Stack trace: " . $e->getTraceAsString());
            
            // Retornar valores de error explícitos
            return [
                'duration' => PHP_INT_MAX,
                'distance' => PHP_INT_MAX,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Construye la URL para la API de Mapbox Directions
     */
    protected function buildRouteUrl($coords)
    {
        // Convertir coordenadas a formato "lon,lat;lon,lat;..."
        $coordinatesString = implode(';', array_map(function($coord) {
            return $coord[0] . ',' . $coord[1]; // lon,lat
        }, $coords));

        return "https://api.mapbox.com/directions/v5/mapbox/driving/{$coordinatesString}";
    }

    /**
     * Hace la llamada HTTP a Mapbox
     */
    protected function makeHttpRequest($url)
    {
        $response = Http::timeout(10)->get($url, [
            'access_token' => $this->accessToken,
            'overview' => 'simplified',
            'geometries' => 'geojson'
        ]);

        return [
            'status' => $response->status(),
            'body' => $response->json(),
            'successful' => $response->successful()
        ];
    }

    /**
     * Procesa la respuesta de Mapbox y extrae duración y distancia
     */
    protected function processMapboxResponse($response)
    {
        if (!$response['successful'] || !isset($response['body']['routes'][0])) {
            throw new \Exception("Respuesta inválida de Mapbox API");
        }

        $route = $response['body']['routes'][0];
        
        return [
            'duration' => (int) $route['duration'], // segundos
            'distance' => (int) $route['distance']  // metros
        ];
    }
}