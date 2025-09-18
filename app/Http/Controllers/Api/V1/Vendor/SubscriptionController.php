<?php

namespace App\Http\Controllers\Api\V1\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\SubscriptionFeature; // Modelo nuevo
use App\Models\SubscriptionTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\CentralLogics\Helpers;

class SubscriptionController extends Controller
{
    /**
     * NUEVO: Devuelve la lista de todas las funciones de suscripción disponibles.
     * Reemplaza a package_view()
     */
    public function get_features()
    {
        $features = SubscriptionFeature::where('status', true)->get();
        return response()->json($features, 200);
    }

    public function purchase_package(Request $request)
{
    $validator = Validator::make($request->all(), [
        'package_id' => 'required|exists:packages,id',
        'payment_method' => 'required|in:wallet,offline',
        // Validaciones condicionales para el pago offline
        'offline_method_id' => 'required_if:payment_method,offline|exists:offline_payment_methods,id',
        'payment_note' => 'nullable|string|max:1000',
        'payment_proof' => 'required_if:payment_method,offline|image|max:2048', // Ejemplo: imagen de 2MB max
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => Helpers::error_processor($validator)], 403);
    }

    $vendor = $request->user();
    $store = $vendor->stores[0];
    $package = Package::find($request->package_id);

    // --- Lógica de Pago con Billetera ---
    if ($request->payment_method == 'wallet') {
        if ($store->vendor->wallet->balance < $package->price) {
            return response()->json(['errors' => [['code' => 'wallet', 'message' => translate('messages.Insufficient_balance_in_wallet')]]], 403);
        }

        DB::beginTransaction();
        try {
            // 1. Cobrar de la billetera
            $wallet = $store->vendor->wallet;
            $wallet->total_withdrawn += $package->price;
            $wallet->save();

            // 2. Crear o actualizar la suscripción de la tienda
            // (Aquí va tu lógica para asignar el paquete a la tienda, similar a como lo hicimos con las features)
            StoreSubscription::updateOrCreate(
                ['store_id' => $store->id],
                [
                    'package_id' => $package->id,
                    'expiry_date' => Carbon::now()->addDays($package->validity),
                    'status' => 1
                ]
            );

            // 3. Registrar la transacción como PAGADA
            SubscriptionTransaction::create([
                'store_id' => $store->id,
                'package_id' => $package->id,
                'price' => $package->price,
                'paid_amount' => $package->price,
                'payment_method' => 'wallet',
                'payment_status' => 'paid', // ¡PAGADO!
                'package_details' => json_encode($package),
                'created_by' => 'vendor',
                // ...otros campos requeridos...
            ]);

            DB::commit();
            return response()->json(['message' => 'Package purchased successfully!'], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error processing wallet payment.', 'error' => $e->getMessage()], 500);
        }
    }

    // --- Lógica de Pago Offline ---
    elseif ($request->payment_method == 'offline') {
        DB::beginTransaction();
        try {
            // 1. Guardar el comprobante de pago
            $proof_path = $request->file('payment_proof')->store('public/subscription_proofs');

            // 2. Registrar la transacción como PENDIENTE
            SubscriptionTransaction::create([
                'store_id' => $store->id,
                'package_id' => $package->id,
                'price' => $package->price,
                'paid_amount' => $package->price, // Se registra el monto esperado
                'payment_method' => 'offline',
                'payment_status' => 'unpaid', // ¡PENDIENTE DE PAGO!
                'reference' => $proof_path, // Guardamos la ruta del comprobante
                'package_details' => json_encode($package),
                'created_by' => 'vendor',
                // ...otros campos requeridos...
            ]);

            // IMPORTANTE: No se activa el paquete todavía.
            // Solo se crea el registro para que el admin lo revise.

            DB::commit();
            return response()->json(['message' => 'Offline payment submitted for review.'], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error submitting offline payment.', 'error' => $e->getMessage()], 500);
        }
    }
}

    /**
     * NUEVO: Devuelve las funciones activas para la tienda que realiza la petición.
     */
    public function get_my_active_features(Request $request)
    {
        // Tu API usa 'vendor' para la autenticación, así que lo adaptamos
        $store = $request->vendor->stores[0];
        
        $active_features = DB::table('store_subscription_features')
            ->join('subscription_features', 'store_subscription_features.subscription_feature_id', '=', 'subscription_features.id')
            ->where('store_subscription_features.store_id', $store->id)
            ->where('store_subscription_features.expires_at', '>', now())
            ->select('subscription_features.name', 'subscription_features.key', 'store_subscription_features.expires_at')
            ->get();

        return response()->json($active_features, 200);
    }

    /**
     * NUEVO: Procesa la compra "a la carta" de una o más funciones.
     * Reemplaza la lógica de suscripción de business_plan()
     */
    public function purchase_features(Request $request)
{
    $validator = Validator::make($request->all(), [
        'feature_ids' => 'required|array|min:1',
        'feature_ids.*' => 'exists:subscription_features,id',
        'validity' => 'required|integer|in:30,90,180,365'
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => Helpers::error_processor($validator)], 403);
    }
    
    $vendor = $request->user();
    $store = $vendor->stores[0];
    
    $feature_ids = $request->input('feature_ids');
    $features = SubscriptionFeature::whereIn('id', $feature_ids)->get();

    if ($features->isEmpty()) {
        return response()->json(['message' => translate('messages.no_valid_features_selected')], 404);
    }

    $total_price = $features->sum('price');
    $discount_percentage = 0.20;
    $final_price = $total_price;
    $applied_discount = 0;

    if ($features->count() >= 3) {
        $applied_discount = $total_price * $discount_percentage;
        $final_price = $total_price - $applied_discount;
    }
    
    if ($store->vendor->wallet->balance < $final_price) {
        return response()->json(['errors' => [['code' => 'wallet', 'message' => translate('messages.Insufficient_balance_in_wallet')]]], 403);
    }

    DB::beginTransaction();
    try {
        $wallet = $store->vendor->wallet;
        $wallet->total_withdrawn += $final_price;
        $wallet->save();

        $validity_days = $request->input('validity');
        $expires_at = Carbon::now()->addDays($validity_days);

        foreach ($features as $feature) {
            $existing_subscription = DB::table('store_subscription_features')
                ->where('store_id', $store->id)
                ->where('subscription_feature_id', $feature->id)
                ->first();

            $new_expiry_date = ($existing_subscription && Carbon::parse($existing_subscription->expires_at)->isFuture())
                ? Carbon::parse($existing_subscription->expires_at)->addDays($validity_days)
                : $expires_at;

            DB::table('store_subscription_features')->updateOrInsert(
                ['store_id' => $store->id, 'subscription_feature_id' => $feature->id],
                ['expires_at' => $new_expiry_date, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        // --- CORRECCIÓN FINAL Y COMPLETA ---
        // Se añaden TODOS los campos que la BD marca como NOT NULL
        SubscriptionTransaction::create([
            'store_id' => $store->id,
            'package_id' => 0,
            'price' => $total_price,
            'validity' => $validity_days,
            'paid_amount' => $final_price,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',                  // <-- CAMPO REQUERIDO 1
            'package_details' => json_encode($features), // <-- CAMPO REQUERIDO 2 (como JSON)
            'created_by' => 'vendor',                   // <-- CAMPO REQUERIDO 3
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::commit();

        return response()->json([
            'message' => translate('messages.subscription_completed_successfully'),
            'total_price' => $total_price,
            'discount' => $applied_discount,
            'final_price' => $final_price
        ], 200);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['message' => translate('messages.error_processing_subscription'), 'error' => $e->getMessage()], 500);
    }
}

    /**
     * MANTENIDO Y ADAPTADO: Devuelve el historial de transacciones de suscripción.
     */
    public function transaction(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required',
            'offset' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $limit = $request['limit'] ?? 25;
        $offset = $request['offset'] ?? 1;
        $store_id = $request->vendor->stores[0]->id;

        $transactions = SubscriptionTransaction::where('store_id', $store_id)
            ->latest()
            ->paginate($limit, ['*'], 'page', $offset);

        return response()->json([
            'total_size' => $transactions->total(),
            'limit' => $limit,
            'offset' => $offset,
            'transactions' => $transactions->items()
        ], 200);
    }
}