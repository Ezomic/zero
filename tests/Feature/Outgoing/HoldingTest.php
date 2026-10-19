<?php

namespace Tests\Feature\Outgoing;

use App\Jobs\SendOutgoingMessageJob;
use App\Models\Contact;
use App\Models\Draft;
use App\Models\MailAccount;
use App\Models\OutgoingMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesMailTransports;
use Tests\TestCase;

class HoldingTest extends TestCase
{
    use FakesMailTransports;
    use RefreshDatabase;

    private User $user;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('outgoing');
        $this->user = User::factory()->create();
        $this->account = MailAccount::factory()->create(['user_id' => $this->user->id]);
    }

    /** @param array<string, mixed> $overrides */
    private function send(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->user)->post(route('compose.store'), array_merge([
            'mail_account_id' => $this->account->id,
            'to' => 'friend@example.com',
            'subject' => 'Lunch',
            'body' => 'Noon?',
        ], $overrides));
    }

    public function test_sending_holds_the_message_and_queues_it_with_a_delay(): void
    {
        Queue::fake();
        Http::fake();
        $this->fakeSmtp();

        $this->send()->assertRedirect(route('inbox.index'))->assertSessionHasNoErrors();

        $message = OutgoingMessage::sole();
        $this->assertSame(OutgoingMessage::HELD, $message->status);
        $this->assertSame([], $this->smtpSent);
        Http::assertNothingSent();

        Queue::assertPushed(SendOutgoingMessageJob::class, function (SendOutgoingMessageJob $job) use ($message): bool {
            return $job->delay instanceof \DateTimeInterface
                && $job->delay->getTimestamp() === $message->send_at->getTimestamp()
                && $job->messageId === $message->id;
        });

        $this->assertEqualsWithDelta(10, now()->diffInSeconds($message->send_at, false), 1);
    }

    public function test_the_hold_comes_from_config_and_is_clamped(): void
    {
        Queue::fake();

        config(['outgoing.hold_seconds' => 30]);
        $this->send();
        $this->assertEqualsWithDelta(30, now()->diffInSeconds(OutgoingMessage::latest('id')->firstOrFail()->send_at, false), 1);

        config(['outgoing.hold_seconds' => 9_999_999]);
        $this->send();
        $this->assertEqualsWithDelta(120, now()->diffInSeconds(OutgoingMessage::latest('id')->firstOrFail()->send_at, false), 1);

        config(['outgoing.hold_seconds' => -5]);
        $this->send();
        $this->assertLessThanOrEqual(0, now()->diffInSeconds(OutgoingMessage::latest('id')->firstOrFail()->send_at, false));
    }

    public function test_a_hold_of_zero_queues_without_delay_and_offers_no_undo(): void
    {
        Queue::fake();
        config(['outgoing.hold_seconds' => 0]);

        $this->send();

        Queue::assertPushed(SendOutgoingMessageJob::class, fn (SendOutgoingMessageJob $job): bool => $job->delay === null);

        $this->fakeSmtp();
        $message = OutgoingMessage::sole();
        app()->call([new SendOutgoingMessageJob($message->id), 'handle']);

        $this->assertCount(1, $this->smtpSent);
        $this->actingAs($this->user)->postJson(route('outgoing.undo', $message->id))->assertStatus(409);
    }

    public function test_the_draft_and_contacts_wait_for_delivery(): void
    {
        Queue::fake();
        $this->fakeSmtp();

        $draft = Draft::create(['user_id' => $this->user->id, 'subject' => 'Lunch', 'body' => 'Noon?']);

        $this->send(['draft_id' => $draft->id]);

        $this->assertSame(1, Draft::count());
        $this->assertSame(0, Contact::count());

        $this->travel(11)->seconds();
        app()->call([new SendOutgoingMessageJob(OutgoingMessage::sole()->id), 'handle']);

        $this->assertSame(0, Draft::count());
        $this->assertSame(1, Contact::count());
    }

    public function test_attachments_are_stored_privately_under_safe_names(): void
    {
        Queue::fake();

        $this->send(['attachments' => [
            UploadedFile::fake()->createWithContent('../../etc/passwd', 'root'),
            UploadedFile::fake()->createWithContent('Überschrift ✓.pdf', 'pdf'),
            UploadedFile::fake()->createWithContent("evil\r\nBcc: x@example.com.txt", 'txt'),
        ]]);

        $message = OutgoingMessage::sole();
        $this->assertCount(3, $message->attachments);

        foreach ($message->attachments as $attachment) {
            $this->assertStringStartsWith("{$message->id}/", $attachment['path']);
            $this->assertStringNotContainsString('..', $attachment['path']);
            Storage::disk('outgoing')->assertExists($attachment['path']);
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x1f\/\\\\]/', $attachment['name']);
        }

        $this->assertSame('Überschrift ✓.pdf', $message->attachments[1]['name']);
    }

    public function test_held_attachments_are_not_reachable_by_url(): void
    {
        Queue::fake();
        $this->send(['attachments' => [UploadedFile::fake()->createWithContent('secret.txt', 'top secret')]]);
        $path = OutgoingMessage::sole()->attachments[0]['path'];

        $this->assertFalse(config('filesystems.disks.outgoing.serve'));
        $this->assertStringNotContainsString(storage_path('app/public'), config('filesystems.disks.outgoing.root'));

        foreach (['/storage/'.$path, '/storage/outgoing/'.$path, '/outgoing/'.$path] as $url) {
            $this->assertContains($this->actingAs($this->user)->get($url)->getStatusCode(), [403, 404, 405], $url);
        }
    }

    public function test_zero_recipients_is_refused_before_anything_is_held(): void
    {
        Queue::fake();

        $this->send(['to' => ' , ,'])->assertSessionHasErrors('to');

        $this->assertSame(0, OutgoingMessage::count());
        Queue::assertNothingPushed();
    }

    public function test_an_unusable_recipient_is_refused_up_front(): void
    {
        Queue::fake();

        $this->send(['to' => 'friend@example.com, not-an-address'])->assertSessionHasErrors('to');
        $this->send(['cc' => 'nope'])->assertSessionHasErrors('cc');

        $this->assertSame(0, OutgoingMessage::count());
    }

    public function test_too_many_attachments_are_refused(): void
    {
        Queue::fake();

        $files = array_map(fn (int $i) => UploadedFile::fake()->createWithContent("f{$i}.txt", 'x'), range(1, 21));

        $this->send(['attachments' => $files])->assertSessionHasErrors('attachments');

        $this->assertSame(0, OutgoingMessage::count());
    }

    public function test_an_oversized_attachment_and_an_oversized_total_are_refused(): void
    {
        Queue::fake();

        $this->send(['attachments' => [UploadedFile::fake()->create('big.bin', 10241)]])
            ->assertSessionHasErrors('attachments.0');

        $this->send(['attachments' => [
            UploadedFile::fake()->create('a.bin', 10240),
            UploadedFile::fake()->create('b.bin', 10240),
            UploadedFile::fake()->create('c.bin', 10240),
        ]])->assertSessionHasErrors('attachments');

        $this->assertSame(0, OutgoingMessage::count());
        $this->assertSame([], Storage::disk('outgoing')->allFiles());
    }

    public function test_a_queue_that_refuses_the_job_does_not_leave_a_message_held(): void
    {
        Bus::shouldReceive('dispatch')->andThrow(new \RuntimeException('queue is down'));
        $this->withoutExceptionHandling();

        try {
            $this->send(['attachments' => [UploadedFile::fake()->createWithContent('a.txt', 'x')]]);
            $this->fail('The queue failure should have surfaced.');
        } catch (\RuntimeException $e) {
            $this->assertSame('queue is down', $e->getMessage());
        }

        $this->assertSame(0, OutgoingMessage::count());
        $this->assertSame([], Storage::disk('outgoing')->allFiles());
    }

    public function test_another_users_account_is_still_refused(): void
    {
        Queue::fake();
        $other = User::factory()->create();

        $this->send([], $other)->assertSessionHasErrors('mail_account_id');

        $this->assertSame(0, OutgoingMessage::count());
    }

    public function test_two_quick_sends_become_two_independent_messages(): void
    {
        Queue::fake();

        $this->send(['subject' => 'One']);
        $this->send(['subject' => 'Two']);

        $this->assertSame(2, OutgoingMessage::count());
        Queue::assertPushed(SendOutgoingMessageJob::class, 2);
    }

    public function test_every_page_carries_the_status_strip_with_the_held_message(): void
    {
        Queue::fake();
        $this->send(['subject' => 'Rendered <b>safely</b>']);

        $html = $this->actingAs($this->user)->get(route('inbox.index'))->assertOk()->getContent();

        $this->assertStringContainsString('id="outgoing-stack"', $html);
        $this->assertStringContainsString('Rendered \u003Cb\u003Esafely\u003C\/b\u003E', $html);
        $this->assertStringNotContainsString('Rendered <b>safely</b>', $html);
    }

    public function test_status_lists_only_my_messages_with_a_countdown(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->send(['subject' => 'Mine']);
        $other = User::factory()->create();
        $theirs = MailAccount::factory()->create(['user_id' => $other->id]);
        $this->send(['subject' => 'Theirs', 'mail_account_id' => $theirs->id], $other);
        $this->assertSame(1, OutgoingMessage::where('user_id', $this->user->id)->count());
        Carbon::setTestNow('2026-10-15 12:00:04');

        $json = $this->actingAs($this->user)->getJson(route('outgoing.index'))->assertOk()->json('messages');

        $this->assertCount(1, $json);
        $this->assertSame('Mine', $json[0]['subject']);
        $this->assertSame('held', $json[0]['status']);
        $this->assertSame(6, $json[0]['seconds_left']);
        $this->assertFalse($json[0]['delayed']);
    }
}
