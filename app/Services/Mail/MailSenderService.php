<?php

namespace App\Services\Mail;

use App\Models\MailAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Webklex\PHPIMAP\ClientManager;

/**
 * Sends a message from a given MailAccount.
 *
 * Gmail and Outlook (OAuth accounts) send via their REST APIs (Gmail API /
 * Microsoft Graph) using the access token directly — this is far more
 * reliable than SMTP XOAUTH2 SASL plumbing. Custom accounts send over plain
 * SMTP with the stored username/password.
 */
class MailSenderService
{
    public function __construct(
        protected OAuthTokenRefresher $tokenRefresher,
    ) {}

    /**
     * @param  array{to: string[], cc?: string[], subject: string, html: string, in_reply_to?: ?string, references?: ?string, attachments?: UploadedFile[]}  $message
     */
    public function send(MailAccount $account, array $message): void
    {
        match ($account->provider) {
            MailAccount::PROVIDER_GMAIL => $this->sendViaGmailApi($account, $message),
            MailAccount::PROVIDER_OUTLOOK => $this->sendViaGraphApi($account, $message),
            default => $this->sendViaSmtp($account, $message),
        };
    }

    /**
     * @param  array{to: string[], cc?: string[], subject: string, html: string, in_reply_to?: ?string, references?: ?string, attachments?: UploadedFile[]}  $message
     */
    protected function sendViaGmailApi(MailAccount $account, array $message): void
    {
        $accessToken = $this->tokenRefresher->freshAccessToken($account);

        $mime = $this->buildMimeMessage($account, $message)->toString();
        $raw = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($mime));

        $response = Http::withToken($accessToken)
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                'raw' => $raw,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Gmail send failed: '.$response->body());
        }
    }

    /**
     * @param  array{to: string[], cc?: string[], subject: string, html: string, in_reply_to?: ?string, references?: ?string, attachments?: UploadedFile[]}  $message
     */
    protected function sendViaGraphApi(MailAccount $account, array $message): void
    {
        $accessToken = $this->tokenRefresher->freshAccessToken($account);

        $headers = [];

        if (! empty($message['in_reply_to'])) {
            $headers[] = ['name' => 'In-Reply-To', 'value' => "<{$message['in_reply_to']}>"];
        }

        if (! empty($message['references'])) {
            $refs = implode(' ', array_map(
                fn ($id) => "<{$id}>",
                $this->splitReferences($message['references'])
            ));
            $headers[] = ['name' => 'References', 'value' => $refs];
        }

        $attachments = collect($message['attachments'] ?? [])
            ->map(fn (UploadedFile $file) => [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $file->getClientOriginalName(),
                'contentType' => $file->getMimeType(),
                'contentBytes' => base64_encode((string) $file->get()),
            ])
            ->all();

        $payload = [
            'message' => array_filter([
                'subject' => $message['subject'],
                'body' => ['contentType' => 'HTML', 'content' => $message['html']],
                'toRecipients' => collect($message['to'])->map(fn ($e) => ['emailAddress' => ['address' => $e]])->all(),
                'ccRecipients' => collect($message['cc'] ?? [])->map(fn ($e) => ['emailAddress' => ['address' => $e]])->all(),
                'internetMessageHeaders' => $headers ?: null,
                'attachments' => $attachments ?: null,
            ], fn ($value) => $value !== null),
            'saveToSentItems' => true,
        ];

        $response = Http::withToken($accessToken)
            ->post('https://graph.microsoft.com/v1.0/me/sendMail', $payload);

        if ($response->failed()) {
            throw new RuntimeException('Outlook send failed: '.$response->body());
        }
    }

    /**
     * @param  array{to: string[], cc?: string[], subject: string, html: string, in_reply_to?: ?string, references?: ?string, attachments?: UploadedFile[]}  $message
     */
    protected function sendViaSmtp(MailAccount $account, array $message): void
    {
        $dsn = sprintf(
            'smtp://%s:%s@%s:%d',
            rawurlencode($account->smtp_username),
            rawurlencode($account->smtp_password),
            $account->smtp_host,
            $account->smtp_port,
        );

        $transport = Transport::fromDsn($dsn);
        $mailer = new Mailer($transport);

        $mime = $this->buildMimeMessage($account, $message);
        $mailer->send($mime);

        $this->appendToSentFolder($account, $mime->toString());
    }

    protected function appendToSentFolder(MailAccount $account, string $rawMessage): void
    {
        try {
            $cm = new ClientManager;
            $client = $cm->make([
                'host' => $account->imap_host,
                'port' => $account->imap_port,
                'encryption' => $account->imap_encryption,
                'validate_cert' => true,
                'username' => $account->imap_username,
                'password' => $account->imap_password,
                'timeout' => 30,
            ]);
            $client->connect();

            // Find the Sent folder — providers name it differently.
            $sentPath = null;
            foreach ($client->getFolders(false) as $folder) {
                if (str_contains(strtolower($folder->name), 'sent')) {
                    $sentPath = $folder->full_name ?? $folder->path;
                    break;
                }
            }

            if ($sentPath) {
                $client->appendMessage($rawMessage, $sentPath, ['Seen']);
            }
        } catch (\Throwable) {
            // Best-effort — a send that succeeded shouldn't fail because the
            // IMAP append didn't work. The next sync will eventually pick it up
            // from the server's Sent folder anyway.
        }
    }

    /**
     * @param  array{to: string[], cc?: string[], subject: string, html: string, in_reply_to?: ?string, references?: ?string, attachments?: UploadedFile[]}  $message
     */
    protected function buildMimeMessage(MailAccount $account, array $message): Email
    {
        $email = (new Email)
            ->from($account->email_address)
            ->to(...$message['to'])
            ->subject($message['subject'])
            ->html($message['html']);

        if (! empty($message['cc'])) {
            $email->cc(...$message['cc']);
        }

        if (! empty($message['in_reply_to'])) {
            $email->getHeaders()->addIdHeader('In-Reply-To', $message['in_reply_to']);
        }

        if (! empty($message['references'])) {
            $refs = $this->splitReferences($message['references']);

            if ($refs) {
                $email->getHeaders()->addIdHeader('References', $refs);
            }
        }

        /** @var UploadedFile $file */
        foreach ($message['attachments'] ?? [] as $file) {
            $email->attach((string) $file->get(), $file->getClientOriginalName(), $file->getMimeType());
        }

        return $email;
    }

    /** @return list<string> */
    protected function splitReferences(string $references): array
    {
        return preg_split('/\s+/', trim($references), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
