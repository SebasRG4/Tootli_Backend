<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class StoreGalleryMedia extends Model
{
    use HasFactory;

    protected $table = 'store_gallery_media';

    protected $fillable = [
        'store_id',
        'url',
        'file_path',
        'file_type',
        'caption',
        'display_order',
    ];

    protected $casts = [
        'display_order' => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

        /**
     * Accesor para obtener la URL pública completa de la imagen.
     * Esta versión es inteligente y maneja tanto las rutas antiguas como las nuevas.
     * 
     * @return string
     */
    public function getFullUrlAttribute(): string
    {
        // Leemos el valor de la columna 'file_path'.
        $pathValue = $this->attributes['file_path'] ?? null;

        // Si el valor está vacío o es nulo, retorna la imagen por defecto.
        if (empty($pathValue)) {
            return asset('assets/admin/img/no-image.png');
        }

        // Si la ruta ya es una URL completa, la retornamos directamente.
        if (str_starts_with($pathValue, 'http')) {
            return $pathValue;
        }

        // ============================================================================
        // === LÓGICA CORREGIDA PARA MANEJAR AMBOS TIPOS DE RUTA ===
        // ============================================================================
        
        // 1. Limpiamos la ruta: Si la ruta empieza con "public/", se lo quitamos.
        //    - 'public/store/gallery/img.jpg' -> 'store/gallery/img.jpg'
        //    - 'store/gallery/img.jpg'        -> 'store/gallery/img.jpg' (no cambia)
        $cleanPath = ltrim($pathValue, 'public/');
        
        // 2. Construimos la URL final.
        //    Ahora, sin importar cómo viniera la ruta, siempre la construimos correctamente.
        return asset('storage/app/public/' . $cleanPath);
    }
}