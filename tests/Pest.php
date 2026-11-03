<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Sends every email through a real mailer whose server answers each
 * recipient by `$answer`: it throws what `$answer` returns, else delivers.
 * Returns every address it was asked to send to, in order.
 * `Mail::fake()` puts a working one back.
 *
 * @param  Closure(string): ?Throwable  $answer
 * @return ArrayObject<int, string>
 */
function fakeMailServer(Closure $answer): ArrayObject
{
    $asked = new ArrayObject;
    $mailer = new MailManager(app());
    $mailer->extend('scripted', fn () => new class($answer, $asked) implements TransportInterface
    {
        /**
         * @param  Closure(string): ?Throwable  $answer
         * @param  ArrayObject<int, string>  $asked
         */
        public function __construct(private Closure $answer, private ArrayObject $asked) {}

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            $envelope ??= Envelope::create($message);
            $to = $envelope->getRecipients()[0]->getAddress();
            $this->asked[] = $to;

            if (($refusal = ($this->answer)($to)) !== null) {
                throw $refusal;
            }

            return new SentMessage($message, $envelope);
        }

        public function __toString(): string
        {
            return 'scripted://';
        }
    });
    config(['mail.default' => 'scripted', 'mail.mailers.scripted' => ['transport' => 'scripted']]);
    Mail::swap($mailer);

    return $asked;
}

/**
 * A mail outage: the server can't be reached for any message.
 *
 * @return ArrayObject<int, string> the addresses it was asked to send to
 */
function breakMailTransport(): ArrayObject
{
    return fakeMailServer(fn (): Throwable => new TransportException('Connection could not be established with host "smtp.example.test:587"'));
}

/**
 * The server's lasting "no" to one message: a 5xx reply at RCPT TO, as for
 * a mistyped or closed mailbox.
 */
function mailRefusal(int $code = 550): UnexpectedResponseException
{
    return new UnexpectedResponseException("Expected response code \"250/251/252\" but got code \"{$code}\", with message \"{$code} 5.1.1 User unknown\".", $code);
}
