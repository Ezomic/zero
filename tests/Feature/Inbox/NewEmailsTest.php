<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewEmailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_rows_newer_than_since_id(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        $old = Email::factory()->create(['mail_account_id' => $account->id]);
        $new = Email::factory()->create(['mail_account_id' => $account->id]);

        $response = $this->actingAs($user)
            ->getJson(route('inbox.newEmails', ['since' => $old->id]));

        $response->assertOk()
            ->assertJson(['newest_id' => $new->id])
            ->assertJsonCount(1, 'html');
    }

    public function test_returns_empty_html_when_no_new_emails(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $email = Email::factory()->create(['mail_account_id' => $account->id]);

        $response = $this->actingAs($user)
            ->getJson(route('inbox.newEmails', ['since' => $email->id]));

        $response->assertOk()
            ->assertJson(['html' => [], 'newest_id' => $email->id]);
    }

    public function test_does_not_return_another_users_emails(): void
    {
        $user = User::factory()->create();
        $otherAccount = MailAccount::factory()->create();
        Email::factory()->create(['mail_account_id' => $otherAccount->id]);

        $response = $this->actingAs($user)
            ->getJson(route('inbox.newEmails', ['since' => 0]));

        $response->assertOk()->assertJson(['html' => []]);
    }
}
