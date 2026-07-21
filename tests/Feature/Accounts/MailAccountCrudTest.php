<?php

namespace Tests\Feature\Accounts;

use App\Jobs\SyncMailAccountJob;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MailAccountCrudTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validImapPayload(array $overrides = []): array
    {
        return [
            'email_address' => 'me@example.com',
            'display_name' => 'Me',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'me@example.com',
            'imap_password' => 'secret',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'me@example.com',
            'smtp_password' => 'secret',
            ...$overrides,
        ];
    }

    public function test_store_creates_an_imap_account_for_the_user_and_queues_a_sync(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('accounts.store'), $this->validImapPayload())
            ->assertRedirect(route('accounts.index'));

        $account = $user->mailAccounts()->first();
        $this->assertNotNull($account);
        $this->assertSame('me@example.com', $account->email_address);
        $this->assertSame(MailAccount::PROVIDER_IMAP, $account->provider);
        $this->assertTrue($account->is_active);
        Queue::assertPushed(SyncMailAccountJob::class);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('accounts.store'), $this->validImapPayload(['email_address' => '', 'imap_host' => '']))
            ->assertSessionHasErrors(['email_address', 'imap_host']);

        $this->assertSame(0, $user->mailAccounts()->count());
    }

    public function test_update_changes_settings_and_keeps_the_password_when_left_blank(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create([
            'user_id' => $user->id,
            'imap_host' => 'old.example.com',
            'imap_password' => 'original-secret',
        ]);

        $this->actingAs($user)
            ->put(route('accounts.update', $account), $this->validImapPayload([
                'imap_host' => 'new.example.com',
                'imap_password' => '',
                'smtp_password' => '',
                'is_active' => '1',
            ]))
            ->assertRedirect(route('accounts.index'));

        $account->refresh();
        $this->assertSame('new.example.com', $account->imap_host);
        $this->assertSame('original-secret', $account->imap_password);
    }

    public function test_update_is_forbidden_for_another_users_account(): void
    {
        $account = MailAccount::factory()->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->put(route('accounts.update', $account), $this->validImapPayload())
            ->assertForbidden();
    }

    public function test_destroy_removes_the_account(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->delete(route('accounts.destroy', $account))
            ->assertRedirect(route('accounts.index'));

        $this->assertDatabaseMissing('mail_accounts', ['id' => $account->id]);
    }

    public function test_destroy_is_forbidden_for_another_users_account(): void
    {
        $account = MailAccount::factory()->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->delete(route('accounts.destroy', $account))
            ->assertForbidden();

        $this->assertDatabaseHas('mail_accounts', ['id' => $account->id]);
    }
}
