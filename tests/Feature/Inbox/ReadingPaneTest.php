<?php

namespace Tests\Feature\Inbox;

use App\Jobs\ApplyEmailFlagJob;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReadingPaneTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_marks_thread_read_and_returns_reading_pane_html(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $email = Email::factory()->create([
            'mail_account_id' => $account->id,
            'subject' => 'Q3 invoice',
            'is_read' => false,
        ]);

        $response = $this->actingAs($user)->get(route('inbox.panel', $email));

        $response->assertOk();
        $response->assertSee('Q3 invoice');
        $this->assertTrue($email->fresh()->is_read);
        Queue::assertPushed(ApplyEmailFlagJob::class);
    }

    public function test_panel_forbidden_for_another_users_email(): void
    {
        $user = User::factory()->create();
        $otherAccount = MailAccount::factory()->create();
        $email = Email::factory()->create(['mail_account_id' => $otherAccount->id]);

        $this->actingAs($user)
            ->get(route('inbox.panel', $email))
            ->assertForbidden();
    }

    public function test_index_with_open_param_preloads_that_thread(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $email = Email::factory()->create([
            'mail_account_id' => $account->id,
            'subject' => 'Preloaded thread',
        ]);

        $response = $this->actingAs($user)->get(route('inbox.index', ['open' => $email->id]));

        $response->assertOk();
        $response->assertViewHas('openThread', fn ($openThread) => $openThread['email']->is($email));
        $response->assertSee('Preloaded thread');
    }

    public function test_index_ignores_open_param_for_another_users_email(): void
    {
        $user = User::factory()->create();
        MailAccount::factory()->create(['user_id' => $user->id]);
        $otherAccount = MailAccount::factory()->create();
        $email = Email::factory()->create(['mail_account_id' => $otherAccount->id]);

        $response = $this->actingAs($user)->get(route('inbox.index', ['open' => $email->id]));

        $response->assertOk();
        $response->assertViewHas('openThread', null);
    }

    public function test_show_derives_list_context_from_the_opened_email(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $email = Email::factory()->create([
            'mail_account_id' => $account->id,
            'folder' => 'SENT',
        ]);

        $response = $this->actingAs($user)->get(route('inbox.show', $email));

        $response->assertOk();
        $response->assertViewHas('folder', 'SENT');
        $response->assertViewHas('selectedAccountId', $account->id);
        $response->assertViewHas('openThread', fn ($openThread) => $openThread['email']->is($email));
    }
}
