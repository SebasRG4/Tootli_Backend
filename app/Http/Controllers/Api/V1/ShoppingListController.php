<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Item;
use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ShoppingListController extends Controller
{
    /**
     * Obtener todas las listas del usuario
     */
    public function index(Request $request): JsonResponse
    {
        $lists = ShoppingList::where('user_id', $request->user()->id)
            ->active()
            ->with(['items' => function($query) {
                $query->with('item:id,name,image,price');
            }])
            ->orderBy('updated_at', 'desc')
            ->paginate(10);

        return response()->json([
            'status' => true,
            'message' => 'Listas obtenidas exitosamente',
            'data' => $lists
        ]);
    }

    /**
     * Crear nueva lista
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.quantity' => 'nullable|string|max:50',
            'items.*.notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        $shoppingList = ShoppingList::create([
            'user_id' => $request->user()->id,
            'name' => $request->name,
            'description' => $request->description
        ]);

        // Agregar items si se proporcionan
        if ($request->has('items')) {
            foreach ($request->items as $itemData) {
                // Buscar producto similar
                $matchedItem = $this->findSimilarProduct($itemData['product_name']);
                
                ShoppingListItem::create([
                    'shopping_list_id' => $shoppingList->id,
                    'item_id' => $matchedItem?->id,
                    'product_name' => $itemData['product_name'],
                    'quantity' => $itemData['quantity'] ?? null,
                    'notes' => $itemData['notes'] ?? null
                ]);
            }
        }

        $shoppingList->load(['items.item']);

        return response()->json([
            'status' => true,
            'message' => 'Lista creada exitosamente',
            'data' => $shoppingList
        ], 201);
    }

    /**
     * Obtener una lista específica
     */
    public function show(Request $request, $id): JsonResponse
    {
        $shoppingList = ShoppingList::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->with(['items' => function($query) {
                $query->with('item:id,name,image,price,store_id')
                      ->orderBy('is_completed')
                      ->orderBy('created_at', 'desc');
            }])
            ->first();

        if (!$shoppingList) {
            return response()->json([
                'status' => false,
                'message' => 'Lista no encontrada'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Lista obtenida exitosamente',
            'data' => $shoppingList
        ]);
    }

    /**
     * Agregar item a la lista
     */
    public function addItem(Request $request, $listId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_name' => 'required|string|max:255',
            'quantity' => 'nullable|string|max:50',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        $shoppingList = ShoppingList::where('user_id', $request->user()->id)
            ->where('id', $listId)
            ->first();

        if (!$shoppingList) {
            return response()->json([
                'status' => false,
                'message' => 'Lista no encontrada'
            ], 404);
        }

        // Buscar producto similar
        $matchedItem = $this->findSimilarProduct($request->product_name);

        $listItem = ShoppingListItem::create([
            'shopping_list_id' => $shoppingList->id,
            'item_id' => $matchedItem?->id,
            'product_name' => $request->product_name,
            'quantity' => $request->quantity,
            'notes' => $request->notes
        ]);

        $listItem->load('item');

        return response()->json([
            'status' => true,
            'message' => 'Item agregado exitosamente',
            'data' => $listItem
        ], 201);
    }

    /**
     * Marcar item como completado/pendiente
     */
    public function toggleItem(Request $request, $listId, $itemId): JsonResponse
    {
        $shoppingList = ShoppingList::where('user_id', $request->user()->id)
            ->where('id', $listId)
            ->first();

        if (!$shoppingList) {
            return response()->json([
                'status' => false,
                'message' => 'Lista no encontrada'
            ], 404);
        }

        $listItem = ShoppingListItem::where('shopping_list_id', $listId)
            ->where('id', $itemId)
            ->first();

        if (!$listItem) {
            return response()->json([
                'status' => false,
                'message' => 'Item no encontrado'
            ], 404);
        }

        if ($listItem->is_completed) {
            $listItem->markAsPending();
        } else {
            $listItem->markAsCompleted();
        }

        return response()->json([
            'status' => true,
            'message' => 'Estado del item actualizado',
            'data' => $listItem->fresh()
        ]);
    }

    /**
     * Buscar productos similares para un item
     */
    public function searchSimilarProducts(Request $request, $listId, $itemId): JsonResponse
    {
        $shoppingList = ShoppingList::where('user_id', $request->user()->id)
            ->where('id', $listId)
            ->first();

        if (!$shoppingList) {
            return response()->json([
                'status' => false,
                'message' => 'Lista no encontrada'
            ], 404);
        }

        $listItem = ShoppingListItem::where('shopping_list_id', $listId)
            ->where('id', $itemId)
            ->first();

        if (!$listItem) {
            return response()->json([
                'status' => false,
                'message' => 'Item no encontrado'
            ], 404);
        }

        $similarProducts = $listItem->similar_products;

        return response()->json([
            'status' => true,
            'message' => 'Productos similares encontrados',
            'data' => $similarProducts
        ]);
    }

    /**
     * Agregar item al carrito
     */
    public function addToCart(Request $request, $listId, $itemId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:items,id',
            'quantity' => 'required|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $product = Item::find($request->product_id);

        // Verificar si ya existe en el carrito
        $existingCart = Cart::where('user_id', $user->id)
            ->where('item_id', $product->id)
            ->where('item_type', get_class($product))
            ->first();

        if ($existingCart) {
            $existingCart->quantity += $request->quantity;
            $existingCart->save();
        } else {
            Cart::create([
                'user_id' => $user->id,
                'module_id' => $product->module_id,
                'item_id' => $product->id,
                'item_type' => get_class($product),
                'price' => $product->price,
                'quantity' => $request->quantity,
                'variation' => json_encode([])
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Producto agregado al carrito exitosamente'
        ]);
    }

    /**
     * Agregar todos los items pendientes al carrito
     */
    public function addAllToCart(Request $request, $listId): JsonResponse
    {
        $shoppingList = ShoppingList::where('user_id', $request->user()->id)
            ->where('id', $listId)
            ->first();

        if (!$shoppingList) {
            return response()->json([
                'status' => false,
                'message' => 'Lista no encontrada'
            ], 404);
        }

        $pendingItems = ShoppingListItem::where('shopping_list_id', $listId)
            ->where('is_completed', false)
            ->whereNotNull('item_id')
            ->with('item')
            ->get();

        $addedCount = 0;
        $user = $request->user();

        foreach ($pendingItems as $listItem) {
            if ($listItem->item) {
                $existingCart = Cart::where('user_id', $user->id)
                    ->where('item_id', $listItem->item->id)
                    ->where('item_type', get_class($listItem->item))
                    ->first();

                if ($existingCart) {
                    $existingCart->quantity += 1;
                    $existingCart->save();
                } else {
                    Cart::create([
                        'user_id' => $user->id,
                        'module_id' => $listItem->item->module_id,
                        'item_id' => $listItem->item->id,
                        'item_type' => get_class($listItem->item),
                        'price' => $listItem->item->price,
                        'quantity' => 1,
                        'variation' => json_encode([])
                    ]);
                }
                $addedCount++;
            }
        }

        return response()->json([
            'status' => true,
            'message' => "$addedCount productos agregados al carrito exitosamente"
        ]);
    }

    /**
     * Eliminar item de la lista
     */
    public function removeItem(Request $request, $listId, $itemId): JsonResponse
    {
        $shoppingList = ShoppingList::where('user_id', $request->user()->id)
            ->where('id', $listId)
            ->first();

        if (!$shoppingList) {
            return response()->json([
                'status' => false,
                'message' => 'Lista no encontrada'
            ], 404);
        }

        $listItem = ShoppingListItem::where('shopping_list_id', $listId)
            ->where('id', $itemId)
            ->first();

        if (!$listItem) {
            return response()->json([
                'status' => false,
                'message' => 'Item no encontrado'
            ], 404);
        }

        $listItem->delete();

        return response()->json([
            'status' => true,
            'message' => 'Item eliminado exitosamente'
        ]);
    }

    /**
     * Eliminar lista completa
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $shoppingList = ShoppingList::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->first();

        if (!$shoppingList) {
            return response()->json([
                'status' => false,
                'message' => 'Lista no encontrada'
            ], 404);
        }

        $shoppingList->delete();

        return response()->json([
            'status' => true,
            'message' => 'Lista eliminada exitosamente'
        ]);
    }

    /**
     * Buscar producto similar basado en el nombre
     */
    private function findSimilarProduct(string $productName): ?Item
    {
        $searchTerm = '%' . $productName . '%';
        
        return Item::active()
            ->when(config('module.current_module_data'), function($query) {
                $query->module(config('module.current_module_data')['id']);
            })
            ->where(function($query) use ($searchTerm) {
                $query->where('name', 'like', $searchTerm)
                      ->orWhereHas('translations', function($q) use ($searchTerm) {
                          $q->where('value', 'like', $searchTerm);
                      });
            })
            ->orderByRaw("CASE WHEN name LIKE ? THEN 1 ELSE 2 END", [$searchTerm])
            ->first();
    }
}