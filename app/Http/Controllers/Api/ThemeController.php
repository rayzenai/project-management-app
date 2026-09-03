<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The theme + font catalogue from config/themes.php, for clients that render
 * appearance settings themselves (the web UI gets the same data as the
 * `themeCatalogue` Inertia prop).
 */
class ThemeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'message' => 'ok',
            'data' => [
                'themes' => config('themes.themes'),
                'font_allow_list' => config('themes.font_allow_list'),
            ],
        ]);
    }
}
