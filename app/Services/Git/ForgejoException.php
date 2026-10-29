<?php

namespace App\Services\Git;

use RuntimeException;
use Throwable;

/**
 * A forge call failed. $status is the HTTP status, or 0 when the forge could
 * not be reached. The message never carries the token.
 */
class ForgejoException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Why an unreachable forge was unreachable (the cURL text), with the token
     * masked, for the operator's log. Empty when there is no cause.
     */
    public function causeMessage(string $token): string
    {
        $message = $this->getPrevious()?->getMessage() ?? '';

        return $token === '' ? $message : str_replace($token, '[token]', $message);
    }
}
