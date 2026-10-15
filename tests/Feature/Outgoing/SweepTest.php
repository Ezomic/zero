<?php

namespace Tests\Feature\Outgoing;

use App\Jobs\SendOutgoingMessageJob;
use App\Models\MailAccount;
use App\Models\OutgoingMessage;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SweepTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('outgoing');
        Queue::fake();
        $this->user = User::factory()->create();
        $this->account = MailAccount::factory()->create(['user_id' => $this->user->id]);
    }

    /** @param array<string, mixed> $attributes */
    private function message(string $status, array $attributes = [], bool $withFile = true): OutgoingMessage
    {
        $message = (new OutgoingMessage)->forceFill(array_merge([
            'user_id' => $this->user->id,
            'mail_account_id' => $this->account->id,
            'status' => $status,
            'send_at' => now(),
            'to_addresses' => ['a@example.com'],
            'cc_addresses' => [],
            'subject' => 'S',
            'body' => 'B',
            'attachments' => [],
        ], $attributes));
        $message->save();

        if ($withFile) {
            $path = "{$message->id}/0-file";
            Storage::disk('outgoing')->put($path, 'x');
            $message->forceFill(['attachments' => [['path' => $path, 'name' => 'f.txt', 'mime' => 'text/plain', 'size' => 1]]])->save();
        }

        return $message;
    }

    public function test_a_held_message_whose_job_was_lost_is_queued_again(): void
    {
        $lost = $this->message(OutgoingMessage::HELD, ['send_at' => now()->subMinutes(5), 'dispatched_at' => now()->subMinutes(5)]);
        $fresh = $this->message(OutgoingMessage::HELD, ['send_at' => now()->addSeconds(5), 'dispatched_at' => now()]);
        $recent = $this->message(OutgoingMessage::HELD, ['send_at' => now()->subSeconds(30), 'dispatched_at' => now()->subSeconds(40)]);

        Artisan::call('outgoing:sweep');

        Queue::assertPushed(SendOutgoingMessageJob::class, 1);
        Queue::assertPushed(SendOutgoingMessageJob::class, fn (SendOutgoingMessageJob $job): bool => $job->messageId === $lost->id);
        $this->assertNotNull($lost->fresh()->dispatched_at);
        $this->assertTrue($lost->fresh()->dispatched_at->isAfter(now()->subMinute()));
        $this->assertNotNull($fresh->fresh());
        $this->assertNotNull($recent->fresh());
    }

    public function test_a_message_stuck_in_sending_is_failed_without_resending(): void
    {
        $stuck = $this->message(OutgoingMessage::SENDING, ['sending_at' => now()->subMinutes(30)]);
        $live = $this->message(OutgoingMessage::SENDING, ['sending_at' => now()->subMinute()]);

        Artisan::call('outgoing:sweep');

        $this->assertSame(OutgoingMessage::FAILED, $stuck->fresh()->status);
        $this->assertStringContainsString('Sent folder', (string) $stuck->fresh()->error);
        $this->assertSame(OutgoingMessage::SENDING, $live->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_expired_takebacks_and_failures_lose_their_attachments(): void
    {
        $old = $this->message(OutgoingMessage::CANCELLED, ['cancelled_at' => now()->subHours(25)]);
        $oldFailed = $this->message(OutgoingMessage::FAILED, ['failed_at' => now()->subHours(25)]);
        $recent = $this->message(OutgoingMessage::CANCELLED, ['cancelled_at' => now()->subHours(2)]);
        $oldPath = $old->attachments[0]['path'];

        Artisan::call('outgoing:sweep');

        $this->assertNull($old->fresh());
        $this->assertNull($oldFailed->fresh());
        Storage::disk('outgoing')->assertMissing($oldPath);
        $this->assertNotNull($recent->fresh());
        Storage::disk('outgoing')->assertExists($recent->attachments[0]['path']);
    }

    public function test_sent_rows_are_dropped_after_a_day(): void
    {
        $sent = $this->message(OutgoingMessage::SENT, ['sent_at' => now()->subHours(25)], withFile: false);
        $justNow = $this->message(OutgoingMessage::SENT, ['sent_at' => now()->subMinutes(2)], withFile: false);

        Artisan::call('outgoing:sweep');

        $this->assertNull($sent->fresh());
        $this->assertNotNull($justNow->fresh());
    }

    public function test_orphaned_attachment_directories_are_removed(): void
    {
        $kept = $this->message(OutgoingMessage::HELD, ['send_at' => now()->addSeconds(5)]);
        Storage::disk('outgoing')->put('987654/0-file', 'orphan');
        Storage::disk('outgoing')->put('not-a-message/0-file', 'junk');
        $this->travel(2)->hours();

        Artisan::call('outgoing:sweep');

        Storage::disk('outgoing')->assertMissing('987654/0-file');
        Storage::disk('outgoing')->assertExists($kept->attachments[0]['path']);
    }

    public function test_a_sent_message_never_keeps_files(): void
    {
        $sent = $this->message(OutgoingMessage::SENT, ['sent_at' => now()->subMinute()]);
        $path = $sent->attachments[0]['path'];
        $this->travel(2)->hours();

        Artisan::call('outgoing:sweep');

        Storage::disk('outgoing')->assertMissing($path);
    }

    public function test_the_sweep_runs_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e): bool => str_contains((string) $e->command, 'outgoing:sweep'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_the_status_flags_a_message_the_queue_has_not_picked_up(): void
    {
        $this->message(OutgoingMessage::HELD, ['send_at' => now()->subMinutes(2)], withFile: false);

        $row = $this->actingAs($this->user)->getJson(route('outgoing.index'))->json('messages.0');

        $this->assertTrue($row['delayed']);
        $this->assertSame(0, $row['seconds_left']);
    }
}
