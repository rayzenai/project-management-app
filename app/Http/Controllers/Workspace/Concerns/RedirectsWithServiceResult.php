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
     * Sent by a page that announces the result itself (the done circle's
     * "Completed ... Undo", My work's row menu): a success flashes nothing,
     * so one action shows one toast. A failure still flashes.
     */
    public const QUIET_HEADER = 'X-Workspace-Quiet';

    /**
     * `$createdKey` names the row a success made (`todo-12`, `task-34`), so
     * the page can find it after the reload (My work opens its group).
     *
     * @param  array{label: string, url: string}|null  $undo
     */
    protected function redirectWithResult(ServiceResult $result, string $successRoute = '', ?string $defaultRoute = null, ?array $undo = null, ?string $createdKey = null): RedirectResponse
    {
        $flash = [
            'success' => $result->success,
            'message' => $result->message,
        ];

        if ($result->success && $undo !== null) {
            $flash['undo'] = $undo;
        }

        if ($result->success && $createdKey !== null) {
            $flash['created_key'] = $createdKey;
        }

        if ($result->success) {
            $redirect = $successRoute ? redirect()->route($successRoute) : back();

            return request()->hasHeader(self::QUIET_HEADER)
                ? $redirect
                : $redirect->with('workspace_flash', $flash);
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
