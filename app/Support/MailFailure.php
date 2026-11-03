<?php

namespace App\Support;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Throwable;

/**
 * What a failed send means to the scheduled emails. `refused`: the mail
 * server said no to this message for good (a 5xx reply, such as an unknown
 * mailbox at RCPT TO), so sending it again gets the same answer.
 * `unreachable`: the server could not take any mail just now (no
 * connection, a timeout, TLS, a refused login, a "try later" 4xx reply), so
 * a later run may. Anything else is not the mail server's doing.
 */
final class MailFailure
{
    public static function refused(Throwable $e): bool
    {
        return $e instanceof UnexpectedResponseException && $e->getCode() >= 500 && $e->getCode() < 600;
    }

    public static function unreachable(Throwable $e): bool
    {
        return $e instanceof TransportExceptionInterface && ! self::refused($e);
    }
}
