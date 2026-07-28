<?php

namespace Tests\Feature\Mail;

use App\Jobs\ApplyEmailFlagJob;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use App\Services\Mail\GraphMailSyncService;
use App\Services\Mail\ImapSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

class ApplyEmailFlagJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_forwards_null_source_uid_for_a_non_move_action(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => MailAccount::PROVIDER_IMAP,
        ]);
        $email = Email::create([
            'mail_account_id' => $account->id,
            'thread_id' => 'thread',
            'folder' => 'INBOX',
            'uid' => '42',
            'subject' => 'Hello',
        ]);

        $imap = Mockery::mock(ImapSyncService::class);
        $imap->shouldReceive('applyAction')
            ->once()
            ->with(Mockery::on(fn (Email $e) => $e->id === $email->id), 'mark_read', null);
        $graph = Mockery::mock(GraphMailSyncService::class);

        (new ApplyEmailFlagJob($email, 'mark_read'))->handle($imap, $graph);
    }

    public function test_source_uid_defaults_to_null_when_reconstructed_without_the_constructor(): void
    {
        // Jobs queued before $sourceUid existed deserialize without running the
        // constructor. The property must carry a real class-level default so it
        // reads as null instead of "must not be accessed before initialization".
        $job = (new ReflectionClass(ApplyEmailFlagJob::class))->newInstanceWithoutConstructor();

        $property = (new ReflectionClass($job))->getProperty('sourceUid');

        $this->assertTrue($property->isInitialized($job));
        $this->assertNull($property->getValue($job));
    }
}
