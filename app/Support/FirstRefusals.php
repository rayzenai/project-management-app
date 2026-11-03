<?php

namespace App\Support;

/**
 * Tells refused recipients from a mail server that refuses everyone (Postfix's
 * "554 Relay access denied" or "553 Sender address rejected" come at RCPT TO,
 * like an unknown mailbox). The refusals among a run's first sends are held
 * while every send so far was refused; when the first COUNT all were, the
 * server is refusing the run (`serverRefuses`), and the scheduled emails treat
 * it as unreachable. Otherwise what was held is the recipients' own refusals,
 * taken at the end of the run. A send counts when it went out or was refused
 * or put off by the server.
 *
 * @template T
 */
final class FirstRefusals
{
    public const COUNT = 3;

    private int $sends = 0;

    /** @var list<T> */
    private array $held = [];

    /**
     * A refused send: true when it is held (every send so far was refused,
     * and it is one of the first COUNT), false when it is the recipient's own.
     *
     * @param  T  $refusal
     */
    public function hold(mixed $refusal): bool
    {
        $this->sends++;

        if ($this->sends > self::COUNT || count($this->held) !== $this->sends - 1) {
            return false;
        }

        $this->held[] = $refusal;

        return true;
    }

    /** A send that went out or was put off: no refusal after it is held. */
    public function passed(): void
    {
        $this->sends++;
    }

    /** The run's first COUNT sends were all refused. */
    public function serverRefuses(): bool
    {
        return count($this->held) === self::COUNT;
    }

    /**
     * Takes the held refusals out.
     *
     * @return list<T>
     */
    public function take(): array
    {
        $held = $this->held;
        $this->held = [];

        return $held;
    }
}
