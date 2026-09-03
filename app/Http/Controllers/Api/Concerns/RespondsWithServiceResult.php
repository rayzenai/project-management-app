<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Support\ServiceResult;
use Illuminate\Http\JsonResponse;

/**
 * Translates a {@see ServiceResult} into a JSON response for the API surface —
 * the JSON sibling of {@see RedirectsWithServiceResult}.
 *
 * Success uses the service's HTTP code (or an explicit override for 201s) and
 * wraps the payload as `{ "message": ..., "data": ... }`. Failure returns the
 * service's code with `{ "message": ..., "errors": {...} }`.
 */
trait RespondsWithServiceResult
{
    /**
     * @param  mixed  $payload  Resource/array to return on success; falls back to $result->data.
     */
    protected function respondWithResult(ServiceResult $result, mixed $payload = null, ?int $successStatus = null): JsonResponse
    {
        if ($result->success) {
            return response()->json([
                'message' => $result->message,
                'data' => $payload ?? $result->data,
            ], $successStatus ?? $result->code ?? 200);
        }

        return response()->json([
            'message' => $result->message ?? 'Something went wrong.',
            'errors' => $this->errorBagFor($result),
        ], $result->code ?? 400);
    }

    /**
     * @return array<string, string>
     */
    private function errorBagFor(ServiceResult $result): array
    {
        if (is_array($result->data) && $result->data !== []) {
            return array_map(
                static fn ($value): string => is_array($value) ? (string) array_values($value)[0] : (string) $value,
                $result->data,
            );
        }

        return ['__global' => $result->message ?? 'Something went wrong.'];
    }
}
