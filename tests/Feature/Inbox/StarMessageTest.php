<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\PendingMirrorAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StarMessageTest extends TestCase
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
    }

    private function message(array $attributes = []): Email
    {
        return Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'folder' => 'INBOX',
            'remote_folder_path' => 'INBOX',
            'uid' => '77',
            'is_starred' => false,
            'is_deleted' => false,
            ...$attributes,
        ]);
    }

    public function test_starring_sets_the_flag_and_queues_the_mirror(): void
    {
        $email = $this->message();

        $this->actingAs($this->user)
            ->post(route('inbox.toggleStar', $email))
            ->assertRedirect();

        $this->assertTrue($email->refresh()->is_starred);
        $this->assertDatabaseHas('pending_mirror_actions', [
            'email_id' => $email->id,
            'action' => 'star',
            'uid' => '77',
        ]);
    }

    public function test_starring_again_removes_it_and_queues_the_opposite(): void
    {
        $email = $this->message(['is_starred' => true]);

        $this->actingAs($this->user)->post(route('inbox.toggleStar', $email));

        $this->assertFalse($email->refresh()->is_starred);
        $this->assertDatabaseHas('pending_mirror_actions', [
            'email_id' => $email->id,
            'action' => 'unstar',
        ]);
    }

    /**
     * Every other action cascades across the thread. A star deliberately does
     * not: starring twelve replies because one mattered would make the
     * Starred view useless (ZERO-113).
     */
    public function test_starring_is_per_message_not_per_thread(): void
    {
        $first = $this->message(['thread_id' => 'shared', 'uid' => '1']);
        $second = $this->message(['thread_id' => 'shared', 'uid' => '2']);

        $this->actingAs($this->user)->post(route('inbox.toggleStar', $first));

        $this->assertTrue($first->refresh()->is_starred);
        $this->assertFalse($second->refresh()->is_starred);
    }

    public function test_another_user_cannot_star_your_mail(): void
    {
        $email = $this->message();

        $this->actingAs(User::factory()->create())
            ->post(route('inbox.toggleStar', $email))
            ->assertForbidden();

        $this->assertFalse($email->refresh()->is_starred);
        $this->assertSame(0, PendingMirrorAction::count());
    }

    public function test_the_starred_view_shows_starred_mail_from_every_folder(): void
    {
        $this->message(['subject' => 'Starred in the inbox', 'is_starred' => true, 'uid' => '1']);
        $this->message(['subject' => 'Starred but archived', 'is_starred' => true, 'is_archived' => true, 'uid' => '2']);
        $this->message(['subject' => 'Starred in a folder', 'is_starred' => true, 'folder' => 'Receipts', 'uid' => '3']);
        $this->message(['subject' => 'Not starred at all', 'uid' => '4']);

        $this->actingAs($this->user)
            ->get(route('inbox.index', ['starred' => 1]))
            ->assertOk()
            ->assertSee('Starred in the inbox')
            ->assertSee('Starred but archived')
            ->assertSee('Starred in a folder')
            ->assertDontSee('Not starred at all');
    }

    public function test_the_starred_view_excludes_deleted_mail(): void
    {
        $this->message(['subject' => 'Starred then deleted', 'is_starred' => true, 'is_deleted' => true]);

        $this->actingAs($this->user)
            ->get(route('inbox.index', ['starred' => 1]))
            ->assertOk()
            ->assertDontSee('Starred then deleted');
    }

    public function test_the_starred_view_shows_nobody_elses_mail(): void
    {
        $other = MailAccount::factory()->create(['user_id' => User::factory()]);
        Email::factory()->create([
            'mail_account_id' => $other->id,
            'subject' => 'Somebody elses star',
            'is_starred' => true,
        ]);

        $this->actingAs($this->user)
            ->get(route('inbox.index', ['starred' => 1]))
            ->assertOk()
            ->assertDontSee('Somebody elses star');
    }

    public function test_the_inbox_row_offers_the_toggle(): void
    {
        $email = $this->message();

        $this->actingAs($this->user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertSee(route('inbox.toggleStar', $email), false)
            ->assertSee('data-action="star"', false);
    }
}
