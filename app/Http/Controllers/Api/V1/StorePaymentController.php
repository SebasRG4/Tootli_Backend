<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StorePayment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StorePaymentController extends Controller
{
    public function pay(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:stores,id',
            'amount' => 'required|numeric|min:0.01',
            'qr_code_value' => 'nullable|string'
        ]);

        $user = $request->user();

        // Verifica saldo suficiente en la wallet
        if ($user->wallet_balance < $request->amount) {
            return response()->json(['message' => 'Saldo insuficiente'], 400);
        }

        DB::beginTransaction();
        try {
            // Descuenta saldo de la wallet del usuario
            $user->wallet_balance -= $request->amount;
            $user->save();

            // Registra el pago en el establecimiento
            $payment = StorePayment::create([
                'user_id' => $user->id,
                'store_id' => $request->store_id,
                'amount' => $request->amount,
                'qr_code_value' => $request->qr_code_value,
                'transaction_status' => 'completed',
            ]);

            DB::commit();
            return response()->json(['success' => true, 'payment' => $payment]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error en el pago', 'error' => $e->getMessage()], 500);
        }
    }
    
    public function userHistory(Request $request)
    {
        $user = $request->user();
        $payments = \App\Models\StorePayment::with('store')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate(20);
    
        return response()->json($payments);
    }
}