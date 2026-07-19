<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShowByRefTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_redirects_a_ulid_reference_to_the_canonical_message_view(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $email = Email::factory()->create(['mail_account_id' => $account->id]);

        $this->actingAs($user)
            ->get(route('inbox.showByUlid', $email->ulid))
            ->assertRedirect(route('inbox.show', $email));
    }

    public function test_it_resolves_to_the_live_row_when_a_stale_duplicate_shares_the_ulid(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $ulid = (string) Str::ulid();

        // The zombie left behind by an out-of-band move, followed by the live
        // row created on the next sync — both carry the same ULID.
        $zombie = Email::factory()->create([
            'mail_account_id' => $account->id,
            'ulid' => $ulid,
            'folder' => 'INBOX',
        ]);
        $live = Email::factory()->create([
            'mail_account_id' => $account->id,
            'ulid' => $ulid,
            'folder' => 'Archive',
        ]);

        $this->assertTrue($live->id > $zombie->id);

        $this->actingAs($user)
            ->get(route('inbox.showByUlid', $ulid))
            ->assertRedirect(route('inbox.show', $live));
    }

    public function test_it_skips_a_soft_deleted_row(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $ulid = (string) Str::ulid();

        $kept = Email::factory()->create([
            'mail_account_id' => $account->id,
            'ulid' => $ulid,
            'is_deleted' => false,
        ]);
        Email::factory()->create([
            'mail_account_id' => $account->id,
            'ulid' => $ulid,
            'is_deleted' => true,
        ]);

        $this->actingAs($user)
            ->get(route('inbox.showByUlid', $ulid))
            ->assertRedirect(route('inbox.show', $kept));
    }

    public function test_it_forbids_another_users_message(): void
    {
        $user = User::factory()->create();
        $otherAccount = MailAccount::factory()->create();
        $email = Email::factory()->create(['mail_account_id' => $otherAccount->id]);

        $this->actingAs($user)
            ->get(route('inbox.showByUlid', $email->ulid))
            ->assertForbidden();
    }

    public function test_it_404s_an_unknown_ulid(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('inbox.showByUlid', (string) Str::ulid()))
            ->assertNotFound();
    }
}
