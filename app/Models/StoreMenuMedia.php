<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreMenuMedia extends Model
{
    use HasFactory;

    protected $table = 'store_menu_media';

    protected $fillable = [
        'store_id',
        'file_path',
        'file_type',
        'title',
        'description',
        'display_order',
    ];

    protected $casts = [
        'display_order' => 'integer',
    ];

    /**
     * Relación: una imagen/video del menú pertenece a una tienda.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Accessor para obtener la URL pública del archivo de menú.
     * Así nunca tendrás doble 'public/' y funciona con la estructura sin storage:link
     */
    public function getFullUrlAttribute()
{
    if (empty($this->file_path)) {
        return env('APP_URL') . '/assets/admin/img/no-image.png';
    }
    if (str_starts_with($this->file_path, 'http')) {
        return $this->file_path;
    }
    // Corrige la URL para que tenga /storage/app/ al inicio
    $relativePath = ltrim($this->file_path, '/');
    return env('APP_URL') . '/storage/app/' . $relativePath;
}
}