<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OfflinePayments;
use App\CentralLogics\Helpers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use MercadoPago\SDK;
use MercadoPago\Payment;
use MercadoPago\Payer;

class MercadoPagoSpeiController extends Controller
{
    private $config_values;

    public function __construct()
    {
        $config = $this->getPaymentConfig('mercadopago');
        if (!is_null($config)) {
            $this->config_values = $config;
            SDK::setAccessToken($this->config_values->access_token);
        }
    }

    private function getPaymentConfig($gateway)
    {
        $digital_payment = Helpers::get_business_settings('digital_payment');
        if(!$digital_payment || $digital_payment['status'] == 0) {
            return null;
        }

        $config = DB::table('addon_settings')
            ->where('key_name', $gateway)
            ->where('settings_type', 'payment_config')
            ->where('is_active', 1)
            ->first();

        if (!$config) return null;

        $env = env('APP_ENV') == 'live' ? 'live' : 'test';
        $credentials = $env . '_values';
        $values = json_decode($config->$credentials);

        return $values && $values->status == 1 ? $values : null;
    }

    /**
     * Crear pago SPEI - se llama cuando el usuario elige pago en línea
     */
    public function createSpeiPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        $order = Order::with('customer', 'offline_payments')->find($request->order_id);
        
        // Verificar que la orden tenga payment_method offline_payment
        if (!$order || $order->payment_method !== 'offline_payment') {
            return response()->json(['error' => 'Orden no válida para SPEI'], 400);
        }

        // Verificar que existe el registro offline_payment con status pending
        if (!$order->offline_payments || $order->offline_payments->status !== 'pending') {
            return response()->json(['error' => 'No hay pago offline pendiente'], 400);
        }

        try {
            // Crear el pago SPEI en MercadoPago
            $payment = new Payment();
            $payment->transaction_amount = (float) $order->order_amount;
            $payment->description = "Pago SPEI - Orden #{$order->id} - Tootli";
            $payment->payment_method_id = "spei";
            $payment->external_reference = $order->id;

            // Información del pagador
            $payer = new Payer();
            if ($order->is_guest) {
                $delivery_address = json_decode($order->delivery_address, true);
                $payer->email = $delivery_address['contact_person_email'] ?? 'guest@tootli.com';
                $payer->first_name = $delivery_address['contact_person_name'] ?? 'Cliente';
            } else {
                $payer->email = $order->customer->email;
                $payer->first_name = $order->customer->f_name;
                $payer->last_name = $order->customer->l_name;
            }
            
            $payment->payer = $payer;
            $payment->notification_url = route('mercadopago.spei.webhook');

            $payment->save();

            if ($payment->status == 'pending') {
                // Actualizar el registro offline_payment existente con info SPEI
                $current_payment_info = json_decode($order->offline_payments->payment_info, true);
                
                // Agregar información SPEI al payment_info existente
                $current_payment_info['spei_clabe'] = $payment->transaction_details->bank_transfer_id;
                $current_payment_info['spei_payment_id'] = $payment->id;
                $current_payment_info['spei_amount'] = $payment->transaction_amount;
                $current_payment_info['spei_status'] = 'pending';
                $current_payment_info['spei_created_at'] = now()->toISOString();
                
                $order->offline_payments->update([
                    'payment_info' => json_encode($current_payment_info),
                    'customer_note' => trim(($order->offline_payments->customer_note ?? '') . " | SPEI Payment ID: {$payment->id}")
                ]);

                $spei_info = [
                    'clabe' => $payment->transaction_details->bank_transfer_id,
                    'payment_id' => $payment->id,
                    'amount' => $payment->transaction_amount,
                    'expiration_date' => $payment->date_of_expiration,
                    'status' => 'pending',
                    'order_id' => $order->id
                ];

                Log::info("SPEI payment created: Order #{$order->id}, MP Payment: {$payment->id}");

                return response()->json([
                    'success' => true,
                    'spei_info' => $spei_info,
                    'message' => 'Pago SPEI creado exitosamente'
                ]);
            } else {
                return response()->json(['error' => 'No se pudo crear el pago SPEI'], 400);
            }

        } catch (\Exception $e) {
            Log::error('Error creando pago SPEI: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error al crear el pago SPEI: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Webhook de MercadoPago - escucha automáticamente los pagos
     */
    public function webhook(Request $request)
    {
        Log::info('MercadoPago SPEI Webhook recibido:', $request->all());

        // Validar firma del webhook
        if (!$this->validateWebhookSignature($request)) {
            Log::warning('Webhook con firma inválida');
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $type = $request->input('type');
        $data_id = $request->input('data.id');

        if ($type === 'payment') {
            $this->handlePaymentNotification($data_id);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    private function validateWebhookSignature(Request $request)
    {
        $signature = $request->header('x-signature');
        $request_id = $request->header('x-request-id');
        
        if (!$signature || !$request_id) {
            return false;
        }

        $elements = explode(',', $signature);
        $ts = null;
        $hash = null;

        foreach ($elements as $element) {
            $keyValue = explode('=', $element, 2);
            if (count($keyValue) === 2) {
                if ($keyValue[0] === 'ts') {
                    $ts = $keyValue[1];
                } elseif ($keyValue[0] === 'v1') {
                    $hash = $keyValue[1];
                }
            }
        }

        if (!$ts || !$hash) {
            return false;
        }

        $manifest = "id:{$request_id};request-id:{$request_id};ts:{$ts};";
        $webhook_secret = $this->config_values->webhook_secret ?? '';
        $expected_signature = hash_hmac('sha256', $manifest, $webhook_secret);

        return hash_equals($hash, $expected_signature);
    }

    private function handlePaymentNotification($payment_id)
    {
        try {
            Log::info("Procesando notificación SPEI: {$payment_id}");
            
            $payment = Payment::find_by_id($payment_id);
            
            if (!$payment) {
                Log::error("Payment not found: {$payment_id}");
                return;
            }

            // Buscar la orden por external_reference
            $order = Order::with('offline_payments')->find($payment->external_reference);
            
            if (!$order || !$order->offline_payments) {
                Log::error("Order or offline_payment not found for: {$payment->external_reference}");
                return;
            }

            // Verificar que el pago corresponde a esta orden
            $payment_info = json_decode($order->offline_payments->payment_info, true);
            if (!isset($payment_info['spei_payment_id']) || $payment_info['spei_payment_id'] != $payment->id) {
                Log::error("SPEI payment ID mismatch for order {$order->id}");
                return;
            }

            switch ($payment->status) {
                case 'approved':
                    $this->autoVerifySpeiPayment($order, $payment);
                    break;
                
                case 'rejected':
                case 'cancelled':
                    $this->markSpeiAsFailed($order, $payment);
                    break;
                
                default:
                    Log::info("Payment status not handled: {$payment->status} for payment {$payment_id}");
            }

        } catch (\Exception $e) {
            Log::error("Error processing SPEI notification: " . $e->getMessage());
        }
    }

    /**
     * Auto-verificar pago SPEI (cambiar status de pending a verified)
     */
    private function autoVerifySpeiPayment($order, $mp_payment)
    {
        // Verificar que no haya sido procesado antes
        if ($order->offline_payments->status === 'verified') {
            Log::info("Payment already verified: Order #{$order->id}");
            return;
        }

        // Validar el monto
        if ((float) $order->order_amount !== (float) $mp_payment->transaction_amount) {
            Log::error("Amount mismatch for Order #{$order->id}: Expected {$order->order_amount}, Got {$mp_payment->transaction_amount}");
            return;
        }

        DB::transaction(function () use ($order, $mp_payment) {
            // CAMBIAR STATUS DE pending A verified AUTOMÁTICAMENTE
            // Esto replica exactamente la lógica de tu OrderController::offline_payment
            
            // 1. Actualizar la orden
            $order->update([
                'payment_status' => 'paid',
                'confirmed' => now(),
                'order_status' => 'confirmed'
            ]);

            // 2. Cambiar status en offline_payments de 'pending' a 'verified'
            $order->offline_payments()->update([
                'status' => 'verified',
                'note' => "Verificado automáticamente vía SPEI el " . now()->format('Y-m-d H:i:s') . ". MP Payment ID: {$mp_payment->id}"
            ]);

            // 3. Actualizar payment_info con confirmación
            $payment_info = json_decode($order->offline_payments->payment_info, true);
            $payment_info['spei_status'] = 'approved';
            $payment_info['spei_verified_at'] = now()->toISOString();
            $payment_info['auto_verified'] = true;
            
            $order->offline_payments()->update([
                'payment_info' => json_encode($payment_info)
            ]);

            // 4. Actualizar payment_method si es partial_payment
            if ($order->payment_method == 'partial_payment') {
                $payment_method_name = $payment_info['method_name'] ?? 'spei';
                $order->payments()->where('payment_status', 'unpaid')->update([
                    'payment_method' => $payment_method_name,
                    'payment_status' => 'paid',
                ]);
            }

            // 5. Decrementar subscription si aplica
            if ($order?->store?->is_valid_subscription == 1 && 
                $order?->store?->store_sub?->max_order != "unlimited" && 
                $order?->store?->store_sub?->max_order > 0) {
                $order?->store?->store_sub?->decrement('max_order', 1);
            }

            // 6. Actualizar payment_method principal
            $order->update(['payment_method' => 'spei']);
        });

        // 7. Enviar notificaciones (usando tu sistema existente)
        Helpers::send_order_notification($order);
        
        // Notificación push al cliente
        $value = Helpers::text_variable_data_format(
            value: Helpers::order_status_update_message('offline_verified', $order->module->module_type),
            store_name: $order->store?->name,
            order_id: $order->id,
            user_name: "{$order?->customer?->f_name} {$order?->customer?->l_name}",
            delivery_man_name: "{$order?->delivery_man?->f_name} {$order?->delivery_man?->l_name}"
        );

        $data = [
            'title' => translate('messages.Your_Offline_payment_is_approved'),
            'description' => $value ?: ' ',
            'order_id' => $order->id,
            'image' => '',
            'type' => 'order_status',
        ];

        $fcm = $order->is_guest == 0 ? $order?->customer?->cm_firebase_token : $order?->guest?->fcm_token;

        if ($fcm && Helpers::getNotificationStatusData('customer', 'customer_offline_payment_approve', 'push_notification_status')) {
            Helpers::send_push_notif_to_device($fcm, $data);
            DB::table('user_notifications')->insert([
                'data' => json_encode($data),
                'user_id' => $order->user_id,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        Log::info("SPEI payment auto-verified: Order #{$order->id} - Status changed from 'pending' to 'verified'");
    }

    /**
     * Marcar SPEI como fallido (mantener pending para revisión manual)
     */
    private function markSpeiAsFailed($order, $mp_payment)
    {
        // Actualizar payment_info pero mantener status como 'pending' para revisión manual
        $payment_info = json_decode($order->offline_payments->payment_info, true);
        $payment_info['spei_status'] = $mp_payment->status;
        $payment_info['spei_failed_at'] = now()->toISOString();
        
        $order->offline_payments()->update([
            'payment_info' => json_encode($payment_info),
            'note' => "SPEI payment {$mp_payment->status} el " . now()->format('Y-m-d H:i:s') . ". MP Payment ID: {$mp_payment->id}. Requiere revisión manual."
        ]);

        Log::info("SPEI payment {$mp_payment->status}: Order #{$order->id} - Mantenido como 'pending' para revisión manual");
    }

    /**
     * Obtener información del pago SPEI para la app
     */
    public function getSpeiInfo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        $order = Order::with('offline_payments')->find($request->order_id);
        
        if (!$order || !$order->offline_payments) {
            return response()->json(['error' => 'Orden no encontrada'], 404);
        }

        $payment_info = json_decode($order->offline_payments->payment_info, true);
        
        if (!isset($payment_info['spei_payment_id'])) {
            return response()->json(['error' => 'No hay información SPEI disponible'], 404);
        }

        return response()->json([
            'clabe' => $payment_info['spei_clabe'] ?? null,
            'payment_id' => $payment_info['spei_payment_id'],
            'amount' => $payment_info['spei_amount'],
            'spei_status' => $payment_info['spei_status'] ?? 'pending',
            'offline_payment_status' => $order->offline_payments->status, // pending, verified, denied
            'order_status' => $order->order_status,
            'payment_status' => $order->payment_status,
            'auto_verified' => $payment_info['auto_verified'] ?? false
        ]);
    }

    /**
     * Endpoint para verificar estado del pago desde la app
     */
    public function checkPaymentStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        $order = Order::with('offline_payments')->find($request->order_id);
        
        if (!$order || !$order->offline_payments) {
            return response()->json(['error' => 'Orden no encontrada'], 404);
        }

        return response()->json([
            'offline_payment_status' => $order->offline_payments->status, // pending, verified, denied
            'order_status' => $order->order_status,
            'payment_status' => $order->payment_status,
            'is_verified' => $order->offline_payments->status === 'verified'
        ]);
    }
}