<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShoppingList extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'is_active',
        'total_items',
        'completed_items'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'total_items' => 'integer',
        'completed_items' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class);
    }

    public function activeItems(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class)->where('is_completed', false);
    }

    public function completedItems(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class)->where('is_completed', true);
    }

    // Calcular progreso automáticamente
    public function updateProgress(): void
    {
        $totalItems = $this->items()->count();
        $completedItems = $this->items()->where('is_completed', true)->count();
        
        $this->update([
            'total_items' => $totalItems,
            'completed_items' => $completedItems
        ]);
    }

    // Scope para listas activas
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Atributo calculado para el progreso
    public function getProgressPercentageAttribute(): float
    {
        if ($this->total_items === 0) {
            return 0.0;
        }
        
        return round(($this->completed_items / $this->total_items) * 100, 2);
    }
}