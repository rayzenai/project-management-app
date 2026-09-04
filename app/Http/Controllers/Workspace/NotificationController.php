<?php

namespace App\Http\Controllers\Workspace;

use App\Queries\NotificationIndexQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationIndexQuery $query): Response
    {
        $payload = $query->get($request);

        return Inertia::render('Notifications/Index', [
            'notifications' => $payload['notifications']->through(fn (DatabaseNotification $n): array => [
                'id' => $n->id,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
                'data' => $n->data,
            ]),
            'filters' => $payload['filters'],
            'counts' => $payload['counts'],
        ]);
    }

    public function read(Request $request, string $id): RedirectResponse
    {
        $request->user()->notifications()->findOrFail($id)->markAsRead();

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
