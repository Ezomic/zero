<?php

namespace Tests\Feature\Inbox;

use App\Jobs\ApplyEmailFlagJob;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InboxMutationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: MailAccount}
     */
    private function userWithAccount(): array
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        return [$user, $account];
    }

    /**
     * @return Collection<int, Email>
     */
    private function thread(MailAccount $account, string $threadId, array $attributes = [])
    {
        return Email::factory()->count(2)->create([
            'mail_account_id' => $account->id,
            'thread_id' => $threadId,
            ...$attributes,
        ]);
    }

    public function test_archive_marks_every_message_in_the_thread_as_archived(): void
    {
        [$user, $account] = $this->userWithAccount();
        $messages = $this->thread($account, 't1', ['is_archived' => false]);

        $this->actingAs($user)
            ->post(route('inbox.archive', $messages->first()))
            ->assertRedirect();

        foreach ($messages as $message) {
            $this->assertTrue($message->refresh()->is_archived);
        }
    }

    public function test_unarchive_moves_the_thread_back_to_the_inbox(): void
    {
        [$user, $account] = $this->userWithAccount();
        $messages = $this->thread($account, 't1', ['is_archived' => true]);

        $this->actingAs($user)
            ->post(route('inbox.unarchive', $messages->first()))
            ->assertRedirect();

        foreach ($messages as $message) {
            $this->assertFalse($message->refresh()->is_archived);
        }
    }

    public function test_mark_unread_flags_the_thread_and_queues_a_flag_job_per_message(): void
    {
        Queue::fake();
        [$user, $account] = $this->userWithAccount();
        $messages = $this->thread($account, 't1', ['is_read' => true]);

        $this->actingAs($user)
            ->post(route('inbox.markUnread', $messages->first()))
            ->assertRedirect(route('inbox.index'));

        foreach ($messages as $message) {
            $this->assertFalse($message->refresh()->is_read);
        }
        Queue::assertPushed(ApplyEmailFlagJob::class, 2);
    }

    public function test_destroy_soft_deletes_the_thread_and_queues_delete_jobs(): void
    {
        Queue::fake();
        [$user, $account] = $this->userWithAccount();
        $messages = $this->thread($account, 't1', ['is_deleted' => false]);

        $this->actingAs($user)
            ->delete(route('inbox.destroy', $messages->first()))
            ->assertRedirect(route('inbox.index'));

        foreach ($messages as $message) {
            $this->assertTrue($message->refresh()->is_deleted);
        }
        Queue::assertPushed(ApplyEmailFlagJob::class, 2);
    }

    public function test_move_relabels_the_thread_nulls_the_uid_and_queues_move_jobs(): void
    {
        Queue::fake();
        [$user, $account] = $this->userWithAccount();
        MailFolder::create([
            'mail_account_id' => $account->id,
            'local_name' => 'Receipts',
            'remote_path' => 'Receipts',
        ]);
        $messages = $this->thread($account, 't1', ['folder' => 'INBOX']);

        $this->actingAs($user)
            ->post(route('inbox.move', $messages->first()), ['folder' => 'Receipts'])
            ->assertRedirect(route('inbox.index'));

        foreach ($messages as $message) {
            $message->refresh();
            $this->assertSame('Receipts', $message->folder);
            $this->assertNull($message->uid);
        }
        Queue::assertPushed(ApplyEmailFlagJob::class, 2);
    }

    public function test_move_to_an_unknown_folder_is_rejected(): void
    {
        [$user, $account] = $this->userWithAccount();
        $email = $this->thread($account, 't1')->first();

        $this->actingAs($user)
            ->post(route('inbox.move', $email), ['folder' => 'DoesNotExist'])
            ->assertStatus(422);
    }

    public function test_bulk_archive_cascades_to_every_message_in_each_selected_thread(): void
    {
        [$user, $account] = $this->userWithAccount();
        $threadA = $this->thread($account, 'a', ['is_archived' => false]);
        $threadB = $this->thread($account, 'b', ['is_archived' => false]);

        $this->actingAs($user)
            ->post(route('inbox.bulk'), [
                'action' => 'archive',
                'ids' => [$threadA->first()->id, $threadB->first()->id],
            ])
            ->assertRedirect();

        foreach ($threadA->merge($threadB) as $message) {
            $this->assertTrue($message->refresh()->is_archived);
        }
    }

    public function test_bulk_delete_soft_deletes_and_queues_jobs(): void
    {
        Queue::fake();
        [$user, $account] = $this->userWithAccount();
        $messages = $this->thread($account, 't1', ['is_deleted' => false]);

        $this->actingAs($user)
            ->post(route('inbox.bulk'), [
                'action' => 'delete',
                'ids' => [$messages->first()->id],
            ])
            ->assertRedirect();

        foreach ($messages as $message) {
            $this->assertTrue($message->refresh()->is_deleted);
        }
        Queue::assertPushed(ApplyEmailFlagJob::class, 2);
    }

    public function test_bulk_rejects_an_unknown_action(): void
    {
        [$user, $account] = $this->userWithAccount();
        $email = $this->thread($account, 't1')->first();

        $this->actingAs($user)
            ->post(route('inbox.bulk'), ['action' => 'nuke', 'ids' => [$email->id]])
            ->assertSessionHasErrors('action');
    }

    public function test_a_user_cannot_mutate_another_users_conversation(): void
    {
        [, $account] = $this->userWithAccount();
        $email = $this->thread($account, 't1')->first();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->post(route('inbox.archive', $email))
            ->assertForbidden();
    }
}
