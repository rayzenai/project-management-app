<?php

namespace App\Support;

use Throwable;

/**
 * Standard return shape for action services. A typed, readonly DTO that
 * services return instead of throwing on expected-failure paths.
 *
 * Convention: services NEVER throw on expected-failure paths; they return
 * ServiceResult::failure(). They only throw on truly exceptional conditions, in
 * which case the caller wraps via ServiceResult::fromException().
 */
final readonly class ServiceResult
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function __construct(
        public bool $success,
        public mixed $data = null,
        public ?string $message = null,
        public ?int $code = null,
        public ?array $meta = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function success(mixed $data = null, ?string $message = null, ?array $meta = null): self
    {
        return new self(
            success: true,
            data: $data,
            message: $message ?? 'Success!',
            code: 200,
            meta: $meta,
        );
    }

    public static function failure(string $message, int $code = 400, mixed $data = null): self
    {
        return new self(
            success: false,
            data: $data,
            message: $message,
            code: $code,
        );
    }

    public static function fromException(Throwable $e): self
    {
        $code = $e->getCode();
        if (! is_int($code) || $code < 100 || $code > 599) {
            $code = 500;
        }

        return new self(
            success: false,
            data: null,
            message: $e->getMessage(),
            code: $code,
        );
    }

    public function failed(): bool
    {
        return ! $this->success;
    }
}
