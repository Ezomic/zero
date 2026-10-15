<?php

namespace Tests\Feature\Outgoing;

use App\Jobs\SendOutgoingMessageJob;
use App\Models\Contact;
use App\Models\MailAccount;
use App\Models\OutgoingMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesMailTransports;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use FakesMailTransports;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('outgoing');
        $this->user = User::factory()->create();
    }

    private function hold(MailAccount $account, bool $withAttachment = false): OutgoingMessage
    {
        $this->actingAs($this->user)->post(route('compose.store'), [
            'mail_account_id' => $account->id,
            'to' => 'friend@example.com',
            'cc' => 'Boss <boss@example.com>',
            'subject' => 'Lunch',
            'body' => "Noon?\n<b>x</b>",
            'attachments' => $withAttachment ? [UploadedFile::fake()->createWithContent('menu.pdf', 'MENU-BYTES')] : [],
        ])->assertSessionHasNoErrors();

        return OutgoingMessage::latest('id')->firstOrFail();
    }

    private function runJob(OutgoingMessage $message, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            app()->call([new SendOutgoingMessageJob($message->id), 'handle']);
        }
    }

    public function test_nothing_leaves_before_the_hold_expires(): void
    {
        Queue::fake();
        Http::fake();
        $this->fakeSmtp();
        $message = $this->hold(MailAccount::factory()->create(['user_id' => $this->user->id]));

        $this->travel(5)->seconds();
        Queue::push(new SendOutgoingMessageJob($message->id));
        $this->runJob($message);

        $this->assertSame([], $this->smtpSent);
        $this->assertSame([], $this->appendedToSent);
        Http::assertNothingSent();
        $this->assertSame(OutgoingMessage::HELD, $message->fresh()->status);
    }

    public function test_the_real_database_queue_does_not_hand_the_job_to_a_worker_until_the_hold_expires(): void
    {
        config(['queue.default' => 'database']);
        Http::fake();
        $this->fakeSmtp();
        if (! Schema::connection('sqlite_system')->hasTable('jobs')) {
            Schema::connection('sqlite_system')->create('jobs', function ($table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
        DB::connection('sqlite_system')->table('jobs')->delete();

        $message = $this->hold(MailAccount::factory()->create(['user_id' => $this->user->id]));

        $row = DB::connection('sqlite_system')->table('jobs')->sole();
        $this->assertSame($message->send_at->getTimestamp(), (int) $row->available_at);

        $this->travel(5)->seconds();
        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);
        $this->assertSame([], $this->smtpSent);
        $this->assertSame(1, DB::connection('sqlite_system')->table('jobs')->count());

        $this->travel(6)->seconds();
        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);
        $this->assertCount(1, $this->smtpSent);
        $this->assertSame(OutgoingMessage::SENT, $message->fresh()->status);
    }

    public function test_a_custom_account_sends_over_smtp_and_appends_to_sent_once(): void
    {
        Queue::fake();
        $this->fakeSmtp();
        $account = MailAccount::factory()->create(['user_id' => $this->user->id, 'email_address' => 'me@example.com']);
        $message = $this->hold($account, withAttachment: true);
        $path = $message->attachments[0]['path'];

        $this->travel(11)->seconds();
        $this->runJob($message);

        $this->assertCount(1, $this->smtpSent);
        $this->assertCount(1, $this->appendedToSent);
        $this->assertStringContainsString('Subject: Lunch', $this->appendedToSent[0]);
        $this->assertStringContainsString(base64_encode('MENU-BYTES'), $this->appendedToSent[0]);
        $this->assertStringContainsString('To: friend@example.com', $this->smtpSent[0]);
        $this->assertStringContainsString('Cc: Boss <boss@example.com>', $this->smtpSent[0]);
        $this->assertStringContainsString('menu.pdf', $this->smtpSent[0]);
        $this->assertStringContainsString(base64_encode('MENU-BYTES'), $this->smtpSent[0]);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $this->smtpSent[0]);

        $fresh = $message->fresh();
        $this->assertSame(OutgoingMessage::SENT, $fresh->status);
        $this->assertSame('', $fresh->body);
        Storage::disk('outgoing')->assertMissing($path);
        $this->assertSame(['boss@example.com', 'friend@example.com'], Contact::orderBy('email')->pluck('email')->all());
    }

    public function test_a_gmail_account_sends_through_the_api(): void
    {
        Queue::fake();
        Http::fake(['https://gmail.googleapis.com/*' => Http::response(['id' => '1'], 200)]);
        $account = MailAccount::factory()->gmail()->create([
            'user_id' => $this->user->id,
            'oauth_access_token' => 'tok',
            'oauth_expires_at' => now()->addHour(),
        ]);
        $message = $this->hold($account, withAttachment: true);
        Http::assertNothingSent();

        $this->travel(11)->seconds();
        $this->runJob($message, times: 2);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'gmail/v1/users/me/messages/send')
            && str_contains(base64_decode(strtr($request['raw'], '-_', '+/')), 'menu.pdf'));
        $this->assertSame(OutgoingMessage::SENT, $message->fresh()->status);
    }

    public function test_an_outlook_account_sends_through_graph(): void
    {
        Queue::fake();
        Http::fake(['https://graph.microsoft.com/*' => Http::response('', 202)]);
        $account = MailAccount::factory()->outlook()->create([
            'user_id' => $this->user->id,
            'oauth_access_token' => 'tok',
            'oauth_expires_at' => now()->addHour(),
        ]);
        $message = $this->hold($account, withAttachment: true);

        $this->travel(11)->seconds();
        $this->runJob($message);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'me/sendMail')
            && $request['message']['attachments'][0]['name'] === 'menu.pdf'
            && $request['message']['attachments'][0]['contentBytes'] === base64_encode('MENU-BYTES'));
        $this->assertSame(OutgoingMessage::SENT, $message->fresh()->status);
    }

    public function test_a_retried_job_never_sends_twice(): void
    {
        Queue::fake();
        $this->fakeSmtp();
        $message = $this->hold(MailAccount::factory()->create(['user_id' => $this->user->id]));

        $this->travel(11)->seconds();
        $this->runJob($message, times: 3);

        $this->assertCount(1, $this->smtpSent);
    }

    public function test_a_message_a_dead_worker_left_sending_is_not_sent_again(): void
    {
        Queue::fake();
        $this->fakeSmtp();
        $message = $this->hold(MailAccount::factory()->create(['user_id' => $this->user->id]));
        $message->forceFill(['status' => OutgoingMessage::SENDING, 'sending_at' => now()])->save();

        $this->travel(11)->seconds();
        $this->runJob($message);

        $this->assertSame([], $this->smtpSent);
        $this->assertSame(OutgoingMessage::SENDING, $message->fresh()->status);
    }

    public function test_an_smtp_failure_is_recorded_for_the_user_and_the_draft_is_kept(): void
    {
        Queue::fake();
        $this->fakeSmtp('Connection refused');
        $message = $this->hold(MailAccount::factory()->create(['user_id' => $this->user->id]), withAttachment: true);
        $path = $message->attachments[0]['path'];

        $this->travel(11)->seconds();
        $this->runJob($message);

        $fresh = $message->fresh();
        $this->assertSame(OutgoingMessage::FAILED, $fresh->status);
        $this->assertStringContainsString('Connection refused', (string) $fresh->error);
        $this->assertSame([], $this->appendedToSent);
        Storage::disk('outgoing')->assertExists($path);
        $this->assertSame(0, Contact::count());

        $row = $this->actingAs($this->user)->getJson(route('outgoing.index'))->assertOk()->json('messages.0');
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('Connection refused', $row['error']);
        $this->assertSame(route('compose.create', ['restore' => $message->id]), $row['restore_url']);
    }

    public function test_a_gmail_error_response_is_recorded(): void
    {
        Queue::fake();
        Http::fake(['https://gmail.googleapis.com/*' => Http::response('quota exceeded', 429)]);
        $account = MailAccount::factory()->gmail()->create([
            'user_id' => $this->user->id,
            'oauth_access_token' => 'tok',
            'oauth_expires_at' => now()->addHour(),
        ]);
        $message = $this->hold($account);

        $this->travel(11)->seconds();
        $this->runJob($message);

        $this->assertSame(OutgoingMessage::FAILED, $message->fresh()->status);
        $this->assertStringContainsString('quota exceeded', (string) $message->fresh()->error);
    }

    public function test_an_expired_token_that_cannot_be_refreshed_is_recorded(): void
    {
        Queue::fake();
        Http::fake(['https://oauth2.googleapis.com/*' => Http::response('invalid_grant', 400)]);
        $account = MailAccount::factory()->gmail()->create([
            'user_id' => $this->user->id,
            'oauth_access_token' => 'old',
            'oauth_expires_at' => now()->addSeconds(5),
        ]);
        $message = $this->hold($account);

        $this->travel(11)->seconds();
        $this->runJob($message);

        $this->assertSame(OutgoingMessage::FAILED, $message->fresh()->status);
        $this->assertStringContainsString('token refresh failed', (string) $message->fresh()->error);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'messages/send'));
    }

    public function test_an_account_removed_during_the_hold_fails_visibly(): void
    {
        Queue::fake();
        $this->fakeSmtp();
        $account = MailAccount::factory()->create(['user_id' => $this->user->id]);
        $message = $this->hold($account);

        $this->actingAs($this->user)->delete(route('accounts.destroy', $account))->assertRedirect();

        $this->travel(11)->seconds();
        $this->runJob($message);

        $fresh = $message->fresh();
        $this->assertSame(OutgoingMessage::FAILED, $fresh->status);
        $this->assertStringContainsString('account was removed', (string) $fresh->error);
        $this->assertSame([], $this->smtpSent);
    }

    public function test_a_job_that_is_killed_mid_send_is_reported_as_unconfirmed(): void
    {
        Queue::fake();
        $message = $this->hold(MailAccount::factory()->create(['user_id' => $this->user->id]));
        $message->forceFill(['status' => OutgoingMessage::SENDING, 'sending_at' => now()])->save();

        (new SendOutgoingMessageJob($message->id))->failed(new \RuntimeException('timed out'));

        $fresh = $message->fresh();
        $this->assertSame(OutgoingMessage::FAILED, $fresh->status);
        $this->assertStringContainsString('Sent folder', (string) $fresh->error);
    }

    public function test_a_job_whose_message_is_gone_does_nothing(): void
    {
        $this->fakeSmtp();

        app()->call([new SendOutgoingMessageJob(424242), 'handle']);

        $this->assertSame([], $this->smtpSent);
    }

    public function test_two_messages_sent_back_to_back_each_go_out_once(): void
    {
        Queue::fake();
        $this->fakeSmtp();
        $account = MailAccount::factory()->create(['user_id' => $this->user->id]);
        $first = $this->hold($account);
        $second = $this->hold($account);

        $this->travel(11)->seconds();
        $this->runJob($second);
        $this->runJob($first);
        $this->runJob($second);

        $this->assertCount(2, $this->smtpSent);
    }
}
