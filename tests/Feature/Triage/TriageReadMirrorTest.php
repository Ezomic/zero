<?php

namespace Tests\Feature\Triage;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\PendingMirrorAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Triage marks a conversation read as it files it. That has to reach the
 * server: otherwise it stays unread everywhere else, and once ZERO-90's
 * reconciliation runs against the destination folder the server still reports
 * it unseen and flips it back here too (ZERO-101).
 */
class TriageReadMirrorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::factory()->create();
        $this->account = MailAccount::factory()->create(['user_id' => $this->user->id]);

        foreach (['INBOX', 'Receipts'] as $name) {
            MailFolder::create([
                'mail_account_id' => $this->account->id,
                'local_name' => $name,
                'remote_path' => $name,
            ]);
        }
    }

    private function unreadMessage(array $attributes = []): Email
    {
        return Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'thread_id' => 't1',
            'folder' => 'INBOX',
            'remote_folder_path' => 'INBOX',
            'uid' => '4242',
            'is_read' => false,
            'is_deleted' => false,
            ...$attributes,
        ]);
    }

    private function triageInto(Email $email, string $folder = 'Receipts'): void
    {
        $this->actingAs($this->user)
            ->post(route('triage.move', $email), ['folder' => $folder])
            ->assertRedirect();
    }

    public function test_filing_an_unread_conversation_queues_the_read_flag_too(): void
    {
        $email = $this->unreadMessage();

        $this->triageInto($email);

        $this->assertTrue($email->refresh()->is_read);
        $this->assertDatabaseHas('pending_mirror_actions', [
            'email_id' => $email->id,
            'action' => 'mark_read',
            'uid' => '4242',
            'remote_folder_path' => 'INBOX',
        ]);
    }

    public function test_the_move_is_still_queued_alongside_it(): void
    {
        $email = $this->unreadMessage();

        $this->triageInto($email);

        $this->assertDatabaseHas('pending_mirror_actions', [
            'email_id' => $email->id,
            'action' => 'move:Receipts',
            'uid' => '4242',
        ]);
        $this->assertSame(2, PendingMirrorAction::where('email_id', $email->id)->count());
    }

    /**
     * applyFolderActions() applies flags before moves, and both IMAP MOVE and
     * Graph's move carry flags with the message, so the flag has to be queued
     * against the source folder and uid for it to land on the moved copy.
     */
    public function test_the_read_flag_targets_the_message_where_it_still_is(): void
    {
        $email = $this->unreadMessage();

        $this->triageInto($email);

        $markRead = PendingMirrorAction::where('email_id', $email->id)->where('action', 'mark_read')->sole();
        $move = PendingMirrorAction::where('email_id', $email->id)->where('action', 'move:Receipts')->sole();

        $this->assertSame('INBOX', $markRead->remote_folder_path);
        $this->assertSame($move->uid, $markRead->uid);
        // Queued first, so the ordering is right even for a drain that walks
        // the rows in id order (which is what the Graph path does).
        $this->assertLessThan($move->id, $markRead->id);
    }

    public function test_an_already_read_conversation_does_not_queue_a_redundant_flag(): void
    {
        $email = $this->unreadMessage(['is_read' => true]);

        $this->triageInto($email);

        $this->assertSame(0, PendingMirrorAction::where('email_id', $email->id)->where('action', 'mark_read')->count());
        $this->assertSame(1, PendingMirrorAction::where('email_id', $email->id)->count());
    }

    public function test_every_message_in_the_thread_gets_its_flag_mirrored(): void
    {
        $first = $this->unreadMessage(['uid' => '10']);
        $second = $this->unreadMessage(['uid' => '11']);

        $this->triageInto($first);

        foreach ([$first, $second] as $message) {
            $this->assertTrue($message->refresh()->is_read);
            $this->assertSame(
                1,
                PendingMirrorAction::where('email_id', $message->id)->where('action', 'mark_read')->count(),
            );
        }
    }
}
