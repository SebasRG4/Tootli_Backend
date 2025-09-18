<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DineOut extends Model
{
    protected $table = 'dine_outs';

    protected $fillable = [
        'store_id',
        'nombre_restaurante',
        'direccion',
        'latitude',
        'longitude',
        'tipo_cocina',
        'precio_promedio',
        'descripcion',
        'menu_descripcion',
        'has_sound',
        'sound_url',
        'has_guarantee_seal',
        'serves_alcohol',
        'disponible',
        'user_id',
        'created_at',
        'updated_at',
    ];

    // Relación con Store
    public function store()
    {
        return $this->belongsTo(\App\Models\Store::class, 'store_id');
    }
}