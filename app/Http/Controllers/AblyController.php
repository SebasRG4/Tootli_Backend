<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Ably\AblyRest;
use App\Models\DeliveryMan;

class AblyController extends Controller
{
    public function getToken(Request $request) // Cambiar nombre del método
    {
        // Obtener el token del header Authorization
        $authToken = $request->bearerToken();
        
        if (!$authToken) {
            return response()->json(['error' => 'Token de autorización requerido'], 401);
        }

        // Buscar el repartidor por su auth_token
        $dm = DeliveryMan::where('auth_token', $authToken)->first();
        
        if (!$dm) {
            return response()->json(['error' => 'Repartidor no encontrado'], 401);
        }

        $driverId = (string) $dm->id; // Usar el ID del repartidor logueado

        // Tu API key de Ably
        $apiKey = env('ABLY_API_KEY');
        
        if (!$apiKey) {
            return response()->json(['error' => 'ABLY_API_KEY no configurada'], 500);
        }

        if (!str_contains($apiKey, ':')) {
            return response()->json(['error' => 'ABLY_API_KEY debe tener formato keyName:keySecret'], 500);
        }

        try {
            $ably = new AblyRest($apiKey);

            $capability = [
                "public:ubicacion-repartidor-{$driverId}" => ["publish", "subscribe"]
            ];

            $tokenDetails = $ably->auth->requestToken([
                'clientId' => $driverId,
                'capability' => $capability,
                'ttl' => 60 * 60 * 1000,
            ]);

            // Retornar en el formato que espera Flutter
            return response()->json([
                'token' => $tokenDetails->token,
                'expires' => $tokenDetails->expires,
                'clientId' => $tokenDetails->clientId,
                'channel_example' => "public:ubicacion-repartidor-{$driverId}",
                'debug_info' => [
                    'driver_id' => $driverId,
                    'driver_name' => $dm->f_name . ' ' . $dm->l_name
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error generando token: ' . $e->getMessage(),
                'debug_info' => [
                    'driver_id' => $driverId,
                    'api_key_configured' => !empty($apiKey)
                ]
            ], 500);
        }
    }
    
    public function token(Request $request)
    {
        $driverId = $request->input('driver_id');
        
        if (!$driverId) {
            return response()->json(['error' => 'driver_id es requerido'], 400);
        }

        // Asegura que driverId sea string
        $driverId = (string) $driverId;

        // Tu API key de Ably, formato: "keyName:keySecret"
        $apiKey = env('ABLY_API_KEY');
        
        if (!$apiKey) {
            return response()->json(['error' => 'ABLY_API_KEY no configurada'], 500);
        }

        // Validar formato de API key
        if (!str_contains($apiKey, ':')) {
            return response()->json(['error' => 'ABLY_API_KEY debe tener formato keyName:keySecret'], 500);
        }

        try {
            // Inicializa el cliente Ably
            $ably = new AblyRest($apiKey);

            // Define las capacidades del token - MATCH EXACTO CON API KEY
            $capability = [
            "public:ubicacion-repartidor-{$driverId}" => ["publish", "subscribe"]
            ];

            // Solicita el Ably Token final
            $tokenDetails = $ably->auth->requestToken([
                'clientId' => $driverId,
                'capability' => $capability, // Pasar el array directamente
                'ttl' => 60 * 60 * 1000,
                
            ]);

            // Retorna el token y detalles
            return response()->json([
                'token' => $tokenDetails->token,
                'expires' => $tokenDetails->expires,
                'clientId' => $tokenDetails->clientId,
                'channel_example' => "public:ubicacion-repartidor-{$driverId}",
                'debug_info' => [
                    'driver_id_type' => gettype($driverId),
                    'driver_id_value' => $driverId
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error generando token: ' . $e->getMessage(),
                'debug_info' => [
                    'driver_id' => $driverId,
                    'driver_id_type' => gettype($driverId),
                    'api_key_format' => str_contains($apiKey, ':') ? 'correcto' : 'incorrecto'
                ]
            ], 500);
        }
    }

    public function testToken(Request $request)
    {
        $driverId = $request->input('driver_id', '2'); // Cambiar a string por defecto
        
        // Asegurar que sea string
        $driverId = (string) $driverId;
        
        // Genera el token
        $tokenRequest = new Request(['driver_id' => $driverId]);
        $tokenResponse = $this->token($tokenRequest);
        $tokenData = json_decode($tokenResponse->getContent(), true);
        
        if (isset($tokenData['error'])) {
            return response()->json($tokenData, 500);
        }

        $token = $tokenData['token'];
        $channel = "public:ubicacion-repartidor-{$driverId}";

        // Prueba publicar con el token
        $result = $this->publishWithToken($token, $channel, 'test_event', [
            'message' => 'Prueba desde Laravel',
            'timestamp' => time(),
            'driver_id' => $driverId
        ]);

        return response()->json([
            'token_info' => $tokenData,
            'publish_result' => $result
        ]);
    }

    private function publishWithToken($token, $channel, $eventName, $data)
    {
        $url = "https://rest.ably.io/channels/{$channel}/messages";
        
        $payload = [
            'name' => $eventName,
            'data' => is_string($data) ? $data : json_encode($data)
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'success' => $httpCode === 201,
            'http_code' => $httpCode,
            'response' => $response,
            'error' => $error ?: null
        ];
    }
}