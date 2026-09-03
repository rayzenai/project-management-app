<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use stdClass;
use Throwable;

/**
 * HTTP response envelope. Wraps a {@see ServiceResult} into a JsonResponse.
 *
 * Use these helpers in controllers; never echo the ServiceResult directly.
 */
trait ApiResponser
{
    protected function emptyResponse(?string $message = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'meta' => null,
            'data' => null,
            'message' => $message ?? 'Nothing found!',
        ], $code);
    }

    protected function dataResponse(mixed $data, ?string $message = null): JsonResponse
    {
        return response()->json([
            'meta' => null,
            'data' => $data,
            'message' => $message ?? 'Success!',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    protected function successResponse(?array $meta = null, mixed $data = [], string $message = 'Results found!', int $code = 200): JsonResponse
    {
        return response()->json([
            'meta' => $meta ?: new stdClass,
            'data' => $data,
            'message' => $message,
        ], $code);
    }

    protected function errorResponse(string $message = 'error', int $code = 404, mixed $detail = null): JsonResponse
    {
        return response()->json([
            'error' => [
                'message' => $message,
                'code' => $code,
                'detail' => $detail,
            ],
        ], $code);
    }

    protected function invalidResponse(string $message = 'Invalid input!', mixed $detail = null): JsonResponse
    {
        return response()->json([
            'error' => [
                'message' => $message,
                'detail' => $detail,
            ],
        ], 422);
    }

    protected function exceptionResponse(Throwable $t): JsonResponse
    {
        return response()->json([
            'error' => [
                'message' => app()->hasDebugModeEnabled() ? $t->getMessage() : 'Error occurred! We are notifying the system admin about this issue!',
                'code' => 500,
            ],
        ], 500);
    }

    /**
     * Translate a {@see ServiceResult} into a JSON envelope. Useful when the
     * caller wants to expose a service via a JSON endpoint.
     */
    protected function jsonFromResult(ServiceResult $result): JsonResponse
    {
        if ($result->success) {
            return $this->successResponse(
                meta: $result->meta,
                data: $result->data,
                message: $result->message ?? 'Success!',
                code: $result->code ?? 200,
            );
        }

        return $this->errorResponse(
            message: $result->message ?? 'error',
            code: $result->code ?? 400,
            detail: $result->data,
        );
    }
}
