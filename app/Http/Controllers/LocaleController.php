<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLocaleRequest;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Cookie;

/**
 * The language switch (the top bar's, the sign-in page's): saved as the
 * person's preference when they are signed in, and in a cookie either way,
 * so the sign-in page keeps it. Back to the same page, now in that language.
 * The app saves it with PATCH /api/v1/user/preferences.
 */
class LocaleController extends BaseController
{
    public function update(UpdateLocaleRequest $request): RedirectResponse
    {
        $locale = (string) $request->validated('locale');
        $user = $request->user();

        if ($user instanceof User) {
            $user->preferences()->updateOrCreate(['user_id' => $user->id], ['locale' => $locale]);
        }

        Cookie::queue(Locales::cookie($locale));

        return back();
    }
}
