<?php

namespace App\Models;

use App\CentralLogics\Helpers;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Scopes\ZoneScope;
use App\Models\SystemSetting;

class DeliveryMan extends Authenticatable
{
        use Notifiable;

        protected $casts = [
            'zone_id' => 'integer',
            'status'=>'boolean',
            'active'=>'integer',
            'available'=>'integer',
            'earning'=>'float',
            'store_id'=>'integer',
            'current_orders'=>'integer',
            'vehicle_id'=>'integer',
        ];

        protected $hidden = [
            'password',
            'auth_token',
        ];
    
    // Codigo nuevo implementacion 
    
        public function checkAndActivateCashAcceptance()
        {
        
        $threshold = (int) SystemSetting::getValue('cash_delivery_threshold', 10);

        // Si ya puede aceptar efectivo, no hacer nada
        if ($this->can_accept_cash) {
            return false;
        }

        // Si cumple el umbral de entregas exitosas, activar la aceptación de efectivo
        if ($this->successfulDeliveriesCount() >= $threshold) {
            $this->can_accept_cash = true;
            $this->save();
            return true;
        }

        return false;
        }
        
                public function activeHandoverOrder()
        {
            return $this->hasOne(Order::class, 'delivery_man_id')
                ->where('order_status', 'handover')
                ->latest('updated_at');
        }
        

        public function getCollectedCashAttribute()
        {
            return $this->wallet ? $this->wallet->collected_cash : 0;
        }
        
            
        protected $fillable = [
        'can_accept_cash',
        // aquí puedes agregar otros campos si quieres que sean asignables masivamente
        ];
    
        public function successfulDeliveriesCount()
            {
            return $this->total_delivered_orders()->count();
            }

            public function updateCashLimit()
            {
                $orders = $this->successfulDeliveriesCount();
            
                if ($orders < 10) {
                    $balance = 0; // No puede aceptar efectivo antes de 10 pedidos
                } elseif ($orders == 10) {
                    $balance = 350;
                } elseif ($orders <= 25) {
                    $balance = 500;
                } elseif ($orders <= 55) {
                    $balance = 850;
                } elseif ($orders <= 85) {
                    $balance = 1000;
                } else {
                    $balance = 1000;
                }
            
                // Límite global desde config
                $globalMax = config('tootli.max_cash_balance_global', 1000);
            
                // Retornar el menor de ambos
                return min($balance, $globalMax);
            }
            
            // Asegura que current_orders siempre sea un entero al acceder
    public function getCurrentOrdersAttribute()
    {
        // Si existe el atributo y es numérico, devuélvelo como int
        if (isset($this->attributes['current_orders']) && is_numeric($this->attributes['current_orders'])) {
            return (int) $this->attributes['current_orders'];
        }
        // Si accidentalmente es una colección, devuelve su count
        if (isset($this->attributes['current_orders']) && $this->attributes['current_orders'] instanceof \Illuminate\Support\Collection) {
            \Log::error("DeliveryMan ID {$this->id}: current_orders era colección, se convierte a int automáticamente.");
            return $this->attributes['current_orders']->count();
        }
        // Si no está seteado, calcula cuántas órdenes activas tiene (ajusta el where según tu lógica de órdenes "activas")
        return $this->orders()->whereIn('order_status', ['handover', 'picked_up'])->count();
    }

    // (Opcional, máxima protección) Captura si alguien intenta setear una colección por error
    public function setCurrentOrdersAttribute($value)
    {
        if ($value instanceof \Illuminate\Support\Collection) {
            \Log::error("Intento de asignar una colección a current_orders en DeliveryMan ID {$this->id}, se guarda como count.");
            $this->attributes['current_orders'] = $value->count();
        } else {
            $this->attributes['current_orders'] = $value;
        }
    }
    
    // Termina codigo nuevo

    protected $appends = ['image_full_url','identity_image_full_url'];
    public function total_canceled_orders()
    {
        return $this->hasMany(Order::class)->where('order_status','canceled');
    }
    public function total_ongoing_orders()
    {
        return $this->hasMany(Order::class)->whereIn('order_status',['handover','picked_up']);
    }

    public function userinfo()
    {
        return $this->hasOne(UserInfo::class,'deliveryman_id', 'id');
    }

    public function vehicle()
    {
        return $this->belongsTo(DMVehicle::class);
    }

    public function wallet()
    {
        return $this->hasOne(DeliveryManWallet::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function order_transaction()
    {
        return $this->hasMany(OrderTransaction::class);
    }

    public function todays_earning()
    {
        return $this->hasMany(OrderTransaction::class)->whereDate('created_at',now());
    }

    public function this_week_earning()
    {
        return $this->hasMany(OrderTransaction::class)->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);
    }

    public function this_month_earning()
    {
        return $this->hasMany(OrderTransaction::class)->whereMonth('created_at', date('m'))->whereYear('created_at', date('Y'));
    }

    public function todaysorders()
    {
        return $this->hasMany(Order::class)->whereDate('accepted',now());
    }

    public function total_delivered_orders()
    {
        return $this->hasMany(Order::class)->where('order_status','delivered');
    }

    public function this_week_orders()
    {
        return $this->hasMany(Order::class)->whereBetween('accepted', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);
    }

    public function delivery_history()
    {
        return $this->hasMany(DeliveryHistory::class, 'delivery_man_id');
    }

    public function last_location()
    {
        return $this->hasOne(DeliveryHistory::class, 'delivery_man_id')->latestOfMany();
    }

    public function zone()
    {
        return $this->belongsTo(Zone::class);
    }

    public function reviews()
    {
        return $this->hasMany(DMReview::class);
    }

    public function disbursement_method()
    {
        return $this->hasOne(DisbursementWithdrawalMethod::class)->where('is_default',1);
    }

    public function rating()
    {
        return $this->hasMany(DMReview::class)
            ->select(DB::raw('avg(rating) average, count(delivery_man_id) rating_count, delivery_man_id'))
            ->groupBy('delivery_man_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active', 1)->where('application_status','approved');
    }
    public function scopeInActive($query)
    {
        return $query->where('active', 0)->where('application_status','approved');
    }

    public function scopeEarning($query)
    {
        return $query->where('earning', 1);
    }

    public function scopeAvailable($query)
    {
        return $query->where('current_orders', '<' ,config('dm_maximum_orders')??1);
    }

    public function scopeUnavailable($query)
    {
        return $query->where('current_orders', '>' ,config('dm_maximum_orders')??1);
    }

    public function scopeZonewise($query)
    {
        return $query->where('type','zone_wise');
    }

    public function getImageFullUrlAttribute(){
        $value = $this->image;
        if (count($this->storage) > 0) {
            foreach ($this->storage as $storage) {
                if ($storage['key'] == 'image') {
                    return Helpers::get_full_url('delivery-man',$value,$storage['value']);
                }
            }
        }

        return Helpers::get_full_url('delivery-man',$value,'public');
    }
    public function getIdentityImageFullUrlAttribute(){
        $images = [];
        $value = is_array($this->identity_image)
            ? $this->identity_image
            : ($this->identity_image && is_string($this->identity_image) && $this->isValidJson($this->identity_image)
                ? json_decode($this->identity_image, true)
                : []);
        if ($value){
            foreach ($value as $item){
                $item = is_array($item)?$item:(is_object($item) && get_class($item) == 'stdClass' ? json_decode(json_encode($item), true):['img' => $item, 'storage' => 'public']);
                $images[] = Helpers::get_full_url('delivery-man',$item['img'],$item['storage']);
            }
        }

        return $images;
    }

    private function isValidJson($string)
    {
        json_decode($string);
        return (json_last_error() === JSON_ERROR_NONE);
    }

    public function storage()
    {
        return $this->morphMany(Storage::class, 'data');
    }

    protected static function booted()
    {
        static::addGlobalScope('storage', function ($builder) {
            $builder->with('storage');
        });
        static::addGlobalScope(new ZoneScope);
    }

    protected static function boot()
    {
        parent::boot();
        static::saved(function ($model) {
            if($model->isDirty('image')){
                $value = Helpers::getDisk();

                DB::table('storages')->updateOrInsert([
                    'data_type' => get_class($model),
                    'data_id' => $model->id,
                    'key' => 'image',
                ], [
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

    }
}