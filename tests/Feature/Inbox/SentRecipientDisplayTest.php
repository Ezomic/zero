<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SentRecipientDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_reading_pane_shows_a_to_and_cc_line_with_recipients(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id, 'email_address' => 'info@thijssensoftware.nl']);
        $email = Email::factory()->create([
            'mail_account_id' => $account->id,
            'folder' => 'SENT',
            'from_address' => 'info@thijssensoftware.nl',
            'to_addresses' => ['<info@count-on.nl>', 'Jane Doe <jane@example.com>'],
            'cc_addresses' => ['<cc@example.com>'],
            'subject' => 'Open aanbod',
        ]);

        $response = $this->actingAs($user)->get(route('inbox.panel', $email));

        $response->assertOk();
        $response->assertSee('To:');
        $response->assertSee('info@count-on.nl');
        $response->assertSee('Jane Doe');
        $response->assertSee('Cc:');
        $response->assertSee('cc@example.com');
    }

    public function test_sent_list_row_shows_the_recipient_not_the_sender(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id, 'email_address' => 'info@thijssensoftware.nl']);
        $email = Email::factory()->create([
            'mail_account_id' => $account->id,
            'folder' => 'SENT',
            'from_address' => 'info@thijssensoftware.nl',
            'from_name' => 'Robbin Thijssen',
            'to_addresses' => ['<info@count-on.nl>'],
            'subject' => 'Open aanbod',
        ]);

        $response = $this->actingAs($user)->get(route('inbox.show', $email));

        $response->assertOk();
        $response->assertViewHas('folder', 'SENT');
        $response->assertSee('To: info@count-on.nl');
    }

    public function test_incoming_list_row_still_shows_the_sender(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $email = Email::factory()->create([
            'mail_account_id' => $account->id,
            'folder' => 'INBOX',
            'from_name' => 'Alice Sender',
            'from_address' => 'alice@example.com',
            'subject' => 'Incoming message',
        ]);

        $response = $this->actingAs($user)->get(route('inbox.show', $email));

        $response->assertOk();
        $response->assertSee('Alice Sender');
    }
}
