<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShoppingListItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'shopping_list_id',
        'item_id',
        'product_name',
        'quantity',
        'notes',
        'is_completed',
        'completed_at'
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        // Actualizar progreso cuando se cree/actualice/elimine un item
        static::saved(function ($item) {
            $item->shoppingList->updateProgress();
        });

        static::deleted(function ($item) {
            $item->shoppingList->updateProgress();
        });
    }

    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    // Marcar como completado
    public function markAsCompleted(): void
    {
        $this->update([
            'is_completed' => true,
            'completed_at' => now()
        ]);
    }

    // Marcar como pendiente
    public function markAsPending(): void
    {
        $this->update([
            'is_completed' => false,
            'completed_at' => null
        ]);
    }

    // Buscar productos similares
    public function getSimilarProductsAttribute()
    {
        if (!$this->product_name) {
            return collect([]);
        }

        return Item::active()
            ->when(config('module.current_module_data'), function($query) {
                $query->module(config('module.current_module_data')['id']);
            })
            ->where(function($query) {
                $searchTerm = '%' . $this->product_name . '%';
                $query->where('name', 'like', $searchTerm)
                      ->orWhereHas('translations', function($q) use ($searchTerm) {
                          $q->where('value', 'like', $searchTerm);
                      });
            })
            ->with(['store', 'category'])
            ->limit(10)
            ->get();
    }
}