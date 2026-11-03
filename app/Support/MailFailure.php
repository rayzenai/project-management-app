<?php

namespace App\Support;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Throwable;

/**
 * What a failed send means to the scheduled emails.
 *
 * - `refused`: the server said no to this recipient or this message for
 *   good (a 5xx at RCPT TO or after, such as an unknown mailbox), so
 *   sending it again gets the same answer.
 * - `deferred`: the server said "not now" to this recipient or this
 *   message only (a 4xx at RCPT TO or after, such as a full mailbox), so a
 *   later run may get through, and nobody else waits on it.
 * - `unreachable`: the server took no mail at all (no connection, a
 *   timeout, TLS, a refused login, any reply before RCPT TO, or a 421, the
 *   server closing the session), so the run stops there.
 *
 * Anything else is not the mail server's doing.
 *
 * Symfony names no SMTP stage on its exception, so the stage is read from
 * what it does carry: the replies it expected (only RCPT TO expects
 * "250/251/252") and its transcript of this message's session, which names
 * every command sent (a RCPT TO in it means the failure came at or after
 * one).
 */
final class MailFailure
{
    public static function refused(Throwable $e): bool
    {
        return self::afterRecipient($e) && $e->getCode() >= 500 && $e->getCode() < 600;
    }

    public static function deferred(Throwable $e): bool
    {
        return self::afterRecipient($e) && $e->getCode() >= 400 && $e->getCode() < 500 && $e->getCode() !== 421;
    }

    public static function unreachable(Throwable $e): bool
    {
        return $e instanceof TransportExceptionInterface && ! self::refused($e) && ! self::deferred($e);
    }

    /**
     * The server answered a recipient (RCPT TO) or its message (DATA and
     * the end of it), not the session.
     */
    private static function afterRecipient(Throwable $e): bool
    {
        return $e instanceof UnexpectedResponseException
            && (str_contains($e->getMessage(), 'Expected response code "250/251/252"')
                || str_contains($e->getDebug(), '> RCPT TO:'));
    }
}
