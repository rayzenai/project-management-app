<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\NotificationResource;
use App\Queries\NotificationIndexQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationIndexQuery $query): AnonymousResourceCollection
    {
        // Shares NotificationIndexQuery with the web inbox so ?scope= and ?type=
        // mean the same thing on both surfaces. The collection shape (data +
        // links + meta) is unchanged; filters and counts are additive.
        $payload = $query->get($request, perPage: 20);

        return NotificationResource::collection($payload['notifications'])
            ->additional([
                'message' => 'ok',
                'filters' => $payload['filters'],
                'counts' => $payload['counts'],
            ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['message' => 'ok', 'data' => ['count' => $request->user()->unreadNotifications()->count()]]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->findOrFail($id)->markAsRead();

        return response()->json(['message' => 'Marked read']);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All marked read']);
    }
}
