<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\CentralLogics\StoreLogic;
use App\CentralLogics\CategoryLogic;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Item;
use App\Models\Store as StoreModel; 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Review;
use Illuminate\Support\Facades\DB;
use App\Models\DeliveryZone;


class StoreController extends Controller
{
    public function get_stores(Request $request, $filter_data="all")
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }

        $type = $request->query('type', 'all');
        $store_type = $request->query('store_type', 'all');
        $zone_id= $request->header('zoneId');
        $longitude= $request->header('longitude');
        $latitude= $request->header('latitude');
        $stores = StoreLogic::get_stores($zone_id, $filter_data, $type, $store_type, $request['limit'], $request['offset'], $request->query('featured'),$longitude,$latitude);
        $stores['stores'] = Helpers::store_data_formatting($stores['stores'], true);

        return response()->json($stores, 200);
    }

    public function index(Request $request)
    {
        $deliveryZone = DeliveryZone::where('id', $request->delivery_zone_id)->first();
        if (!$deliveryZone) {
            return response()->json(['message' => 'Delivery zone not found'], 404);
        }
        $stores = StoreModel::where('status', 'active')->where('delivery_zone_id', $deliveryZone->id)->get();

        $formatted_stores = Helpers::store_data_formatting($stores, true);
        return response()->json($formatted_stores, 200);
    }

    public function allStoresForMap()
{
    $stores = StoreModel::with(['galleryMedia', 'menuMedia'])->get();

    $formatted_stores = $stores->map(function($store) {
        $data = Helpers::store_data_formatting($store, false);

        // Galería
        $data['gallery_media'] = $store->galleryMedia->map(function($media) {
            // --- LÓGICA CORREGIDA AQUÍ ---
            // 1. Limpiamos el 'public/' del principio de la ruta
            $cleanPath = str_starts_with($media->file_path, 'public/') 
                ? substr($media->file_path, 7) 
                : $media->file_path;

            return [
                'id' => $media->id,
                // 2. Usamos la ruta limpia para construir la URL
                'url' => asset('storage/app/public/' . $cleanPath),
                'type' => $media->file_type ?? null,
                'caption' => $media->caption ?? null,
                'display_order' => $media->display_order ?? null,
            ];
        })->values();

        // Menú (Aplicamos la misma corrección)
        $data['menu_media'] = $store->menuMedia->map(function($media) {
            // --- LÓGICA CORREGIDA AQUÍ ---
            $cleanPath = str_starts_with($media->file_path, 'public/') 
                ? substr($media->file_path, 7) 
                : $media->file_path;
                
            return [
                'id' => $media->id,
                'url' => asset('storage/app/public/' . $cleanPath),
                'type' => $media->file_type ?? null,
                'title' => $media->title ?? null,
                'description' => $media->description ?? null,
                'display_order' => $media->display_order ?? null,
            ];
        })->values();

        return $data;
    });

    return response()->json($formatted_stores, 200);
}

    public function show(StoreModel $store)
    {
        $formatted_store = Helpers::store_data_formatting($store, false); 
        return response()->json($formatted_store[0] ?? null, 200);
    }

    // ... (El resto de tus métodos permanecen igual) ...
    public function get_latest_stores(Request $request, $filter_data="all")
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }

        $type = $request->query('type', 'all');

        $zone_id= $request->header('zoneId');
        $longitude= $request->header('longitude');
        $latitude= $request->header('latitude');
        $stores = StoreLogic::get_latest_stores($zone_id, $request['limit'], $request['offset'], $type,$longitude,$latitude);
        $stores['stores'] = Helpers::store_data_formatting($stores['stores'], true);

        return response()->json($stores, 200);
    }

    public function get_popular_stores(Request $request)
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }
        $type = $request->query('type', 'all');
        $zone_id= $request->header('zoneId');
        $longitude= $request->header('longitude');
        $latitude= $request->header('latitude');
        $stores = StoreLogic::get_popular_stores($zone_id, $request['limit'], $request['offset'], $type,$longitude,$latitude);
        $stores['stores'] = Helpers::store_data_formatting($stores['stores'], true);

        return response()->json($stores, 200);
    }

    public function get_discounted_stores(Request $request)
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }
        $type = $request->query('type', 'all');
        $zone_id= $request->header('zoneId');
        $longitude= $request->header('longitude');
        $latitude= $request->header('latitude');
        $stores = StoreLogic::get_discounted_stores($zone_id, $request['limit'], $request['offset'], $type,$longitude,$latitude);
        $stores['stores'] = Helpers::store_data_formatting($stores['stores'], true);

        return response()->json($stores, 200);
    }

    public function get_top_rated_stores(Request $request)
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }
        $type = $request->query('type', 'all');
        $zone_id= $request->header('zoneId');
        $longitude= $request->header('longitude');
        $latitude= $request->header('latitude');
        $stores = StoreLogic::get_top_rated_stores($zone_id, $request['limit'], $request['offset'], $type,$longitude,$latitude);
        $stores['stores'] = Helpers::store_data_formatting($stores['stores'], true);

        usort($stores['stores'], function ($a, $b) {
            $key = 'avg_rating';
            return $b[$key] - $a[$key];
        });

        return response()->json($stores, 200);
    }

    public function get_popular_store_items($id)
    {
        $items = Item::
        when(is_numeric($id),function ($qurey) use($id){
            $qurey->where('store_id', $id);
        })
        ->when(!is_numeric($id), function ($query) use ($id) {
            $query->whereHas('store', function ($q) use ($id) {
                $q->where('slug', $id);
            });
        })
        ->active()->popular()->limit(10)->get();
        $items = Helpers::product_data_formatting($items, true, true, app()->getLocale());

        return response()->json($items, 200);
    }

    public function get_details(Request $request, $id)
{
    $longitude = $request->header('longitude');
    $latitude = $request->header('latitude');
    
    $store_object = StoreLogic::get_store_details($id, $longitude, $latitude); // Renombramos a $store_object para claridad

    if ($store_object) {
        $store_id = $store_object->id; // Guardamos el ID antes de formatear

        // Obtenemos los IDs de las categorías usando el ID guardado
        $category_ids = DB::table('items')
            ->join('categories', 'items.category_id', '=', 'categories.id')
            ->selectRaw('categories.position as positions, IF((categories.position = "0"), categories.id, categories.parent_id) as categories')
            ->where('items.store_id', $store_id) // Usamos $store_id
            ->where('categories.status', 1)
            ->groupBy('categories', 'positions')
            ->get();

        // Formateamos el objeto para obtener nuestro array de respuesta base
        $store_data = Helpers::store_data_formatting($store_object, false); // Usamos $multi_data = false

        // Añadimos la información extra al array ya formateado
        $store_data['category_ids'] = array_map('intval', $category_ids->pluck('categories')->toArray());
        $store_data['category_details'] = Category::whereIn('id', $store_data['category_ids'])->get();
        
        // Obtenemos el rango de precios usando el ID guardado
        $store_data['price_range'] = Item::withoutGlobalScopes()->where('store_id', $store_id) // Usamos $store_id
            ->select(DB::raw('MIN(price) AS min_price, MAX(price) AS max_price'))
            ->first() // Usamos first() para obtener un solo objeto de resultado
            ->toArray();
    } else {
        $store_data = null; // Si no se encuentra la tienda, devolvemos null
    }

    return response()->json($store_data, 200);
}

    public function get_searched_stores(Request $request)
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }
        $validator = Validator::make($request->all(), [
            'name' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $type = $request->query('type', 'all');

        $zone_id= $request->header('zoneId');
        $longitude= $request->header('longitude');
        $latitude= $request->header('latitude');
        $stores = StoreLogic::search_stores($request['name'], $zone_id, $request->category_id,$request['limit'], $request['offset'], $type,$longitude,$latitude);
        $stores['stores'] = Helpers::store_data_formatting($stores['stores'], true);
        return response()->json($stores, 200);
    }

    public function reviews(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $id = $request['store_id'];


        $reviews = Review::with(['customer', 'item'])
        ->whereHas('item', function($query)use($id){
            return $query->where('store_id', $id);
        })
        ->active()->latest()->get();

        $storage = [];
        foreach ($reviews as $temp) {
            $temp['attachment'] = json_decode($temp['attachment']);
            $temp['item_name'] = null;
            $temp['item_image'] = null;
            $temp['customer_name'] = null;
            // $temp->item=null;
            if($temp->item)
            {
                $temp['item_name'] = $temp->item->name;
                $temp['item_image'] = $temp->item->image;
                $temp['item_image_full_url'] = $temp->item->image_full_url;
                if(count($temp->item->translations)>0)
                {
                    $translate = array_column($temp->item->translations->toArray(), 'value', 'key');
                    $temp['item_name'] = $translate['name'];
                }
                unset($temp->item);
                $temp['item'] = Helpers::product_data_formatting($temp->item, false, false, app()->getLocale());
            }
            if($temp->customer)
            {
                $temp['customer_name'] = $temp->customer->f_name.' '.$temp->customer->l_name;
            }

            unset($temp['customer']);
            array_push($storage, $temp);
        }

        return response()->json($storage, 200);
    }


    public function get_recommended_stores(Request $request){


        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }
        $type = $request->query('type', 'all');
        $zone_id= $request->header('zoneId');
        $longitude= $request->header('longitude') ?? 0;
        $latitude= $request->header('latitude') ?? 0;
        $stores = StoreLogic::get_recommended_stores($zone_id, $request['limit'], $request['offset'], $type,$longitude,$latitude);
        $stores['stores'] = Helpers::store_data_formatting($stores['stores'], true);

        return response()->json($stores, 200);
    }

    public function get_combined_data(Request $request)
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]];
            return response()->json(['errors' => $errors], 403);
        }

        $zone_id = $request->header('zoneId');
        $data_type = $request->query('data_type', 'all');
        $type = $request->query('type', 'all');
        $limit = $request->query('limit', 10);
        $offset = $request->query('offset', 1);
        $longitude = $request->header('longitude') ?? 0;
        $latitude = $request->header('latitude') ?? 0;
        $filter = $request->query('filter', '');
        $filter = $filter?(is_array($filter)?$filter:str_getcsv(trim($filter, "[]"), ',')):'';
        $rating_count = $request->query('rating_count');

        switch ($data_type) {
            case 'searched':
                $validator = Validator::make($request->all(), ['name' => 'required']);
                if ($validator->fails()) {
                    return response()->json(['errors' => Helpers::error_processor($validator)], 403);
                }
                $name = $request->input('name');

                $paginator = StoreLogic::search_stores($name, $zone_id, $request->category_id, $limit, $offset, $type, $longitude, $latitude, $filter, $rating_count);
                break;

            case 'discounted':

                $paginator = StoreLogic::get_discounted_stores($zone_id, $limit, $offset, $type, $longitude, $latitude, $filter, $rating_count);
                break;

            case 'category':
                $validator = Validator::make($request->all(), [
                    'category_ids' => 'required|array',
                    'category_ids.*' => 'integer'
                ]);

                if ($validator->fails()) {
                    return response()->json(['errors' => Helpers::error_processor($validator)], 403);
                }

                $category_ids = $request->input('category_ids');

                $paginator = CategoryLogic::category_stores($category_ids);
        }
    }
    
    public function get_top_offer_near_me(Request $request)
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }
        $type = $request->query('type', 'all');
        $zone_id= $request->header('zoneId');
        $longitude= $request->header('longitude');
        $latitude= $request->header('latitude');

        $stores = StoreLogic::get_top_offer_near_me(zone_id:$zone_id, limit:$request['limit'], offset: $request['offset'], type: $type, longitude:$longitude,latitude: $latitude,
                    name:$request->name, sort: $request->sort_by ,halal: $request->halal);
        $stores['stores'] = Helpers::store_data_formatting($stores['stores'], true);


        return response()->json($stores, 200);
    }
    
}
