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

        // Every queued action has to carry the uid the message had in INBOX,
        // not the one it will get in Bugsnag, or the drain would address the
        // unrelated message already filed there. That goes for the read flag
        // triage queues alongside the move (ZERO-101) as much as the move.
        $queued = PendingMirrorAction::where('email_id', $inboxEmail->id)->get();

        $this->assertEqualsCanonicalizing(
            ['mark_read', 'move:Bugsnag'],
            $queued->pluck('action')->all(),
        );

        foreach ($queued as $action) {
            $this->assertSame('29', $action->uid, "{$action->action} must address the INBOX copy");
            $this->assertSame('INBOX', $action->remote_folder_path);
        }

        Queue::assertPushed(DrainMirrorActionsJob::class);
    }

    /**
     * The inbox move had the same shape: the action was queued after the row
     * had already been pointed at the destination, so with a null
     * remote_folder_path it recorded the destination as its own source.
     */
    public function test_the_inbox_move_also_records_the_folder_the_message_is_leaving(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        MailFolder::create([
            'mail_account_id' => $account->id,
            'local_name' => 'Bugsnag',
            'remote_path' => 'Bugsnag',
        ]);

        $email = Email::create([
            'mail_account_id' => $account->id,
            'thread_id' => 'inbox-thread',
            'folder' => 'INBOX',
            'uid' => '29',
            'subject' => 'Your Bugsnag plan has been changed',
        ]);

        Queue::fake();

        $this->actingAs($user)
            ->post(route('inbox.move', $email), ['folder' => 'Bugsnag'])
            ->assertRedirect();

        $queued = PendingMirrorAction::where('email_id', $email->id)->sole();

        $this->assertSame('move:Bugsnag', $queued->action);
        $this->assertSame('29', $queued->uid);
        $this->assertSame('INBOX', $queued->remote_folder_path);

        Queue::assertPushed(DrainMirrorActionsJob::class);
    }
}
