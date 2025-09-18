<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ComboKit;
use Illuminate\Http\Request;

class ComboKitController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->get('type', 'all');
        $limit = $request->get('limit', 15);
        $offset = $request->get('offset', 0);

        $query = ComboKit::query();

        if ($type !== 'all') {
            $query->where('type', $type);
        }

        $comboKits = $query->offset($offset)
                          ->limit($limit)
                          ->get();

        return response()->json($comboKits);
    }
}
