<?php

namespace App\Services\OrderAssignment\Filters;


use Illuminate\Support\Facades\Http;
use App\Services\OrderAssignment\Logger\AssignmentLogger;

class MapboxDistanceService
{
    protected $mapboxToken;

    public function __construct()
    {
        $this->mapboxToken = config('services.mapbox.token');
    }

    /**
     * Retorna distancia y duración en metros y segundos entre dos puntos usando Mapbox Directions.
     *
     * @param array $origin ['lat' => float, 'lng' => float]
     * @param array $destination ['lat' => float, 'lng' => float]
     * @return array|null ['distance' => float, 'duration' => float] o null en caso de error
     */
    public function getDistanceAndDuration(array $origin, array $destination)
    {
        if (
            !isset($origin['lat'], $origin['lng']) ||
            !isset($destination['lat'], $destination['lng'])
        ) {
            return null;
        }

        $url = "https://api.mapbox.com/directions/v5/mapbox/driving/{$origin['lng']},{$origin['lat']};{$destination['lng']},{$destination['lat']}";

        $response = Http::get($url, [
            'access_token' => $this->mapboxToken,
            'overview' => 'simplified',
            'geometries' => 'geojson'
        ]);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['routes'][0])) {
                return [
                    'distance' => $data['routes'][0]['distance'], // en metros
                    'duration' => $data['routes'][0]['duration'], // en segundos
                ];
            }
        }

        return null;
    }
}