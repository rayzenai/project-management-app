<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePreferenceRequest;
use App\Models\User;
use App\Services\ResolveThemeTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Token-auth sibling of Workspace\PreferenceController: reads and updates the
 * caller's appearance + notification preferences, returning the resolved theme
 * tokens so a client can paint without a second request.
 */
class PreferenceController extends Controller
{
    public function show(Request $request, ResolveThemeTokens $resolver): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->payload($user, $resolver);
    }

    public function update(UpdatePreferenceRequest $request, ResolveThemeTokens $resolver): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->preferences()->updateOrCreate(
            ['user_id' => $user->id],
            $request->validated(),
        );

        return $this->payload($user->refresh(), $resolver);
    }

    private function payload(User $user, ResolveThemeTokens $resolver): JsonResponse
    {
        $appearance = $user->appearance();

        return response()->json([
            'message' => 'ok',
            'data' => [
                'theme' => $appearance['theme'],
                'font_override' => $appearance['font_override'],
                'email_notifications' => $appearance['email_notifications'],
                'configured' => $user->preferences()->exists(),
                'resolved_tokens' => $resolver->resolved($appearance['theme'], $appearance['font_override']),
            ],
        ]);
    }
}
