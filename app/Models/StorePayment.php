<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorePayment extends Model
{
    protected $table = 'store_payments';

    protected $fillable = [
        'user_id',
        'store_id',
        'amount',
        'qr_code_value',
        'transaction_status',
    ];

    // Relaciones
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}