<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DineOut;
use Illuminate\Http\Request;

class DineOutController extends Controller
{
    public function index()
{
    // Carga la relación store
    $dineOuts = DineOut::with('store')->get();
    $dineOuts = DineOut::with(['store.storeMedia', 'store.socialMedia'])->get();
    
    $result = $dineOuts->map(function ($dineOut) {
            $store = $dineOut->store;
            

    $result = $dineOuts->map(function ($dineOut) {
        return [
            'id' => $dineOut->id,
            'name' => $dineOut->nombre_restaurante,
            'latitude' => $dineOut->latitude,
            'longitude' => $dineOut->longitude,
            'address' => $dineOut->direccion,
            'description' => $dineOut->descripcion,
            'images' => $dineOut->store ? [$dineOut->store->logo_full_url] : [],
            'menu' => [],
            'menu_description' => $dineOut->menu_descripcion,
            'categories' => $dineOut->store ? [$dineOut->tipo_cocina] : [],
            'has_sound' => (bool) $dineOut->has_sound,
            'sound_url' => $dineOut->sound_url,
            'videos' => [],
            'discount_info' => [],
            'has_guarantee_seal' => (bool) $dineOut->has_guarantee_seal,
            'serves_alcohol' => (bool) $dineOut->serves_alcohol,
            'average_cost_per_person' => $dineOut->precio_promedio,
            // ...otros campos si quieres...
        ];
    });

    return response()->json($result);
}
public function edit($id)
    {
        $dineOut = DineOut::findOrFail($id);
        return view('admin-views.dineout.edit', compact('dineOut'));
    }
    public function update(Request $request, $id)
    {
        $dineOut = DineOut::findOrFail($id);
        // Valida los campos según tus necesidades
        $request->validate([
            'nombre_restaurante' => 'required|string|max:255',
            'latitude'           => 'nullable|numeric',
            'longitude'          => 'nullable|numeric',
            'direccion'          => 'nullable|string',
            // ...otros campos
        ]);
        $dineOut->fill($request->all());
        $dineOut->save();

        return redirect()->back()->with('success', 'Restaurante actualizado correctamente.');
    }
}