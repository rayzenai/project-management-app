<?php

namespace App\Http\Controllers\Workspace\Concerns;

use App\Support\ServiceResult;
use Illuminate\Http\RedirectResponse;

/**
 * Translates a {@see ServiceResult} into an Inertia-friendly redirect. Inertia
 * surfaces flash messages via shared props (see HandleInertiaRequests::share)
 * — controllers just `back()->with('flash', ...)` and the Svelte app reads it.
 */
trait RedirectsWithServiceResult
{
    /**
     * @param  array{label: string, url: string}|null  $undo
     */
    protected function redirectWithResult(ServiceResult $result, string $successRoute = '', ?string $defaultRoute = null, ?array $undo = null): RedirectResponse
    {
        $flash = [
            'success' => $result->success,
            'message' => $result->message,
        ];

        if ($result->success && $undo !== null) {
            $flash['undo'] = $undo;
        }

        if ($result->success) {
            return $successRoute
                ? redirect()->route($successRoute)->with('workspace_flash', $flash)
                : back()->with('workspace_flash', $flash);
        }

        return back()
            ->withErrors($this->extractErrorBag($result))
            ->with('workspace_flash', $flash);
    }

    /**
     * @return array<string, string>
     */
    private function extractErrorBag(ServiceResult $result): array
    {
        if (is_array($result->data) && ! empty($result->data)) {
            return array_map(static fn ($v): string => is_array($v) ? (string) array_values($v)[0] : (string) $v, $result->data);
        }

        return ['__global' => $result->message ?? 'Something went wrong.'];
    }
}
