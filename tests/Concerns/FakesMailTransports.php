<?php

namespace Tests\Concerns;

use App\Services\Mail\ImapClientFactory;
use App\Services\Mail\MailSenderService;
use App\Services\Mail\OAuthTokenRefresher;
use Mockery;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Support\FolderCollection;

/**
 * Replaces the SMTP mailer and the IMAP client behind MailSenderService, so a
 * test can prove what would have left the machine without anything leaving it.
 */
trait FakesMailTransports
{
    /** @var list<string> */
    public array $smtpSent = [];

    /** @var list<string> */
    protected array $appendedToSent = [];

    protected function fakeSmtp(?string $failWith = null): void
    {
        $test = $this;

        $mailer = new class($test, $failWith) implements MailerInterface
        {
            public function __construct(private object $test, private ?string $failWith) {}

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                if ($this->failWith !== null) {
                    throw new RuntimeException($this->failWith);
                }

                $this->test->smtpSent[] = $message->toString();
            }
        };

        $folder = Mockery::mock(Folder::class);
        $folder->name = 'Sent';
        $folder->shouldReceive('appendMessage')->andReturnUsing(function (string $raw) {
            $this->appendedToSent[] = $raw;

            return null;
        });

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect');
        $client->shouldReceive('getFolders')->andReturn(new FolderCollection([$folder]));

        $refresher = app(OAuthTokenRefresher::class);

        $service = Mockery::mock(MailSenderService::class, [$refresher, new ImapClientFactory($refresher)])->makePartial();
        $service->shouldAllowMockingProtectedMethods()
            ->shouldReceive('makeMailer')->andReturn($mailer)
            ->shouldReceive('makeImapClient')->andReturn($client);

        $this->app?->instance(MailSenderService::class, $service);
    }
}
