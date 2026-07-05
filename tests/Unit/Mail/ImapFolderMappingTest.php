<?php

namespace Tests\Unit\Mail;

use App\Services\Mail\ImapSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Subclass that exposes the protected folder-name classifier for unit testing
// without requiring a live IMAP connection.
class TestableImapSyncService extends ImapSyncService
{
    public function isAggregateName(string $lower): bool
    {
        return $this->looksLikeAggregateFolderName($lower);
    }
}

class ImapFolderMappingTest extends TestCase
{
    private TestableImapSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $refresher = Mockery::mock(OAuthTokenRefresher::class);
        $this->service = new TestableImapSyncService($refresher);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    #[DataProvider('aggregateFolderProvider')]
    public function test_excludes_gmail_aggregate_folder_names(string $name): void
    {
        $this->assertTrue($this->service->isAggregateName(strtolower($name)));
    }

    public static function aggregateFolderProvider(): array
    {
        return [
            ['[Gmail]'],
            ['All Mail'],
            ['Alle e-mail'],
            ['Important'],
            ['Starred'],
            ['Tous les messages'],
            ['Alle Nachrichten'],
            ['Tutti i messaggi'],
            ['Todos los mensajes'],
            ['Todos os e-mails'],
        ];
    }

    #[DataProvider('canonicalFolderProvider')]
    public function test_does_not_exclude_canonical_mailbox_folders(string $name): void
    {
        $this->assertFalse($this->service->isAggregateName(strtolower($name)));
    }

    public static function canonicalFolderProvider(): array
    {
        return [
            ['INBOX'],
            ['Sent'],
            ['Sent Mail'],
            ['Drafts'],
            ['Trash'],
            ['Deleted Items'],
            ['Spam'],
            ['Junk'],
            ['Work'],
            ['Receipts'],
        ];
    }
}
