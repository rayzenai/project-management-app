<?php

use App\Support\MailFailure;
use Symfony\Component\Mailer\Exception\TransportException;

/*
 * How a failed send is sorted, by the stage the server answered at: the
 * session (unreachable, the run stops), or one recipient or its message
 * (refused for good, or deferred to a later run). The exceptions are built
 * as Symfony's SMTP transport throws them (smtpReply), which a real session
 * against a fake server confirmed.
 */

/** @return 'refused'|'deferred'|'unreachable'|'other' */
function mailFailureKind(Throwable $e): string
{
    return match (true) {
        MailFailure::refused($e) => 'refused',
        MailFailure::deferred($e) => 'deferred',
        MailFailure::unreachable($e) => 'unreachable',
        default => 'other',
    };
}

it('sorts a failed send by where the server answered', function (Throwable $failure, string $kind) {
    expect(mailFailureKind($failure))->toBe($kind);
})->with([
    'no connection' => [fn () => new TransportException('Connection could not be established with host "smtp.example.test:587"'), 'unreachable'],
    'a timeout' => [fn () => new TransportException('Connection to "smtp.example.test:587" timed out.'), 'unreachable'],
    'a refused login' => [fn () => new TransportException('Failed to authenticate on SMTP server with username "no-reply".', 535), 'unreachable'],
    '"too busy" at the greeting' => [fn () => smtpReply(421, 'greeting'), 'unreachable'],
    'a 4xx at MAIL FROM' => [fn () => smtpReply(451, 'MAIL FROM'), 'unreachable'],
    'a 5xx at MAIL FROM' => [fn () => smtpReply(550, 'MAIL FROM'), 'unreachable'],
    'a full mailbox at RCPT TO' => [fn () => smtpReply(452), 'deferred'],
    'an unknown mailbox at RCPT TO' => [fn () => smtpReply(550), 'refused'],
    'a 421 at RCPT TO: the server closing' => [fn () => smtpReply(421), 'unreachable'],
    'a 5xx at DATA' => [fn () => smtpReply(554, 'DATA'), 'refused'],
    'a 4xx after the message' => [fn () => smtpReply(451, 'end of data'), 'deferred'],
    'a 5xx after the message' => [fn () => smtpReply(554, 'end of data'), 'refused'],
    'not the mail server' => [fn () => new LogicException('A broken template'), 'other'],
]);
