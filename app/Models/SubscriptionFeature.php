<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionFeature extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'subscription_features';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'key',
        'description',
        'price',
        'status',
    ];

    /**
     * Define la relación muchos a muchos con las tiendas.
     * Una función puede ser comprada por muchas tiendas.
     */
    public function stores()
    {
        return $this->belongsToMany(Store::class, 'store_subscription_features', 'subscription_feature_id', 'store_id')
                    ->withPivot('expires_at')
                    ->withTimestamps();
    }
}