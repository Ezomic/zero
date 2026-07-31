<?php

namespace Tests\Feature;

use App\Jobs\DrainMirrorActionsJob;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\PendingMirrorAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TriageMoveUidCollisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_moving_an_email_into_a_folder_with_a_colliding_uid_does_not_500(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        MailFolder::create([
            'mail_account_id' => $account->id,
            'local_name' => 'Bugsnag',
            'remote_path' => 'Bugsnag',
        ]);

        // An unrelated message already filed in Bugsnag happens to reuse the
        // same uid the inbox message has in INBOX — legal, since IMAP UIDs
        // are only unique per-folder on the server.
        Email::create([
            'mail_account_id' => $account->id,
            'thread_id' => 'unrelated-thread',
            'folder' => 'Bugsnag',
            'uid' => '29',
            'subject' => 'Unrelated message already filed here',
        ]);

        $inboxEmail = Email::create([
            'mail_account_id' => $account->id,
            'thread_id' => 'inbox-thread',
            'folder' => 'INBOX',
            'uid' => '29',
            'subject' => 'Your Bugsnag plan has been changed',
        ]);

        Queue::fake();

        $response = $this->actingAs($user)
            ->post("/triage/{$inboxEmail->id}/move", ['folder' => 'Bugsnag']);

        $response->assertRedirect();

        $inboxEmail->refresh();
        $this->assertSame('Bugsnag', $inboxEmail->folder);
        $this->assertNull($inboxEmail->uid);

        // The queued action has to carry the uid the message had in INBOX,
        // not the one it will get in Bugsnag, or the drain would address the
        // unrelated message already filed there.
        $queued = PendingMirrorAction::where('email_id', $inboxEmail->id)->sole();
        $this->assertSame('move:Bugsnag', $queued->action);
        $this->assertSame('29', $queued->uid);

        Queue::assertPushed(DrainMirrorActionsJob::class);
    }
}
