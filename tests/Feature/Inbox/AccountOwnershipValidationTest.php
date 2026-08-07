<?php

namespace Tests\Feature\Inbox;

use App\Models\Draft;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A mail_account_id arriving from the browser has to be checked against the
 * caller, not merely against the table. `exists:mail_accounts,id` proves only
 * that the row is real (ZERO-104).
 */
class AccountOwnershipValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MailAccount $mine;

    private MailAccount $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->mine = MailAccount::factory()->create(['user_id' => $this->user->id]);
        $this->theirs = MailAccount::factory()->create(['user_id' => User::factory()]);
    }

    public function test_autosave_rejects_another_users_account(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('drafts.autosave'), [
                'mail_account_id' => $this->theirs->id,
                'subject' => 'Borrowed sender',
                'body' => 'x',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mail_account_id');

        $this->assertSame(0, Draft::count());
    }

    public function test_autosave_accepts_your_own_account(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('drafts.autosave'), [
                'mail_account_id' => $this->mine->id,
                'subject' => 'My own sender',
                'body' => 'x',
            ])
            ->assertOk();

        $this->assertSame($this->mine->id, Draft::sole()->mail_account_id);
    }

    public function test_autosave_still_allows_no_account_at_all(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('drafts.autosave'), ['subject' => 'No sender yet', 'body' => 'x'])
            ->assertOk();

        $this->assertNull(Draft::sole()->mail_account_id);
    }

    public function test_compose_rejects_another_users_account_before_sending_anything(): void
    {
        Http::fake();

        $this->actingAs($this->user)
            ->post(route('compose.store'), [
                'mail_account_id' => $this->theirs->id,
                'to' => 'someone@example.com',
                'subject' => 'Borrowed sender',
                'body' => 'x',
            ])
            ->assertSessionHasErrors('mail_account_id');

        Http::assertNothingSent();
    }
}
