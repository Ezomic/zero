<?php

namespace Tests\Feature\Outgoing;

use App\Jobs\SendOutgoingMessageJob;
use App\Models\MailAccount;
use App\Models\OutgoingMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesMailTransports;
use Tests\TestCase;

class UndoTest extends TestCase
{
    use FakesMailTransports;
    use RefreshDatabase;

    private User $user;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('outgoing');
        Queue::fake();
        Http::fake();
        $this->fakeSmtp();
        $this->user = User::factory()->create();
        $this->account = MailAccount::factory()->create(['user_id' => $this->user->id]);
    }

    private function hold(?User $as = null, bool $withAttachment = false): OutgoingMessage
    {
        $this->actingAs($as ?? $this->user)->post(route('compose.store'), [
            'mail_account_id' => ($as ? $as->mailAccounts()->firstOrFail() : $this->account)->id,
            'to' => 'friend@example.com',
            'cc' => 'boss@example.com',
            'subject' => 'Lunch',
            'body' => "Noon?\nSecond line",
            'in_reply_to' => 'abc@mail.example.com',
            'references' => 'one@x two@x',
            'attachments' => $withAttachment ? [UploadedFile::fake()->createWithContent('menu.pdf', 'MENU')] : [],
        ])->assertSessionHasNoErrors();

        return OutgoingMessage::latest('id')->firstOrFail();
    }

    private function runJob(OutgoingMessage $message): void
    {
        app()->call([new SendOutgoingMessageJob($message->id), 'handle']);
    }

    public function test_undo_cancels_the_hold_and_points_at_the_composer(): void
    {
        $message = $this->hold();

        $this->actingAs($this->user)
            ->postJson(route('outgoing.undo', $message->id))
            ->assertOk()
            ->assertJson(['restore_url' => route('compose.create', ['restore' => $message->id])]);

        $this->assertSame(OutgoingMessage::CANCELLED, $message->fresh()->status);
    }

    public function test_a_cancelled_message_is_not_delivered_even_though_its_job_is_already_queued(): void
    {
        $message = $this->hold();
        Queue::assertPushed(SendOutgoingMessageJob::class);

        $this->actingAs($this->user)->postJson(route('outgoing.undo', $message->id))->assertOk();

        $this->travel(5)->minutes();
        $this->runJob($message);

        $this->assertSame([], $this->smtpSent);
        $this->assertSame([], $this->appendedToSent);
        Http::assertNothingSent();
        $this->assertSame(OutgoingMessage::CANCELLED, $message->fresh()->status);
    }

    public function test_undo_after_the_hold_has_passed_is_refused_and_the_message_stays_sent(): void
    {
        $message = $this->hold();

        $this->travel(11)->seconds();
        $this->runJob($message);

        $this->actingAs($this->user)->postJson(route('outgoing.undo', $message->id))->assertStatus(409);

        $this->assertCount(1, $this->smtpSent);
        $this->assertSame(OutgoingMessage::SENT, $message->fresh()->status);
    }

    public function test_a_message_past_its_time_that_no_worker_has_taken_can_still_be_undone(): void
    {
        $message = $this->hold();

        $this->travel(5)->minutes();

        $this->actingAs($this->user)->postJson(route('outgoing.undo', $message->id))->assertOk();
        $this->runJob($message);

        $this->assertSame([], $this->smtpSent);
        $this->assertSame(OutgoingMessage::CANCELLED, $message->fresh()->status);
    }

    public function test_undoing_twice_is_refused_the_second_time(): void
    {
        $message = $this->hold();

        $this->actingAs($this->user)->postJson(route('outgoing.undo', $message->id))->assertOk();
        $this->actingAs($this->user)->postJson(route('outgoing.undo', $message->id))->assertStatus(409);
    }

    public function test_a_message_that_already_went_or_failed_cannot_be_undone(): void
    {
        $sent = $this->hold();
        $this->travel(11)->seconds();
        $this->runJob($sent);
        $this->actingAs($this->user)->postJson(route('outgoing.undo', $sent->id))->assertStatus(409);

        $this->fakeSmtp('connection refused');
        $failed = $this->hold();
        $this->travel(11)->seconds();
        $this->runJob($failed);
        $this->assertSame(OutgoingMessage::FAILED, $failed->fresh()->status);
        $this->actingAs($this->user)->postJson(route('outgoing.undo', $failed->id))->assertStatus(409);
    }

    public function test_a_stale_id_is_not_found(): void
    {
        $this->actingAs($this->user)->postJson(route('outgoing.undo', 99999))->assertNotFound();
    }

    public function test_someone_elses_message_is_not_found_and_stays_held(): void
    {
        $other = User::factory()->create();
        MailAccount::factory()->create(['user_id' => $other->id]);
        $theirs = $this->hold($other, withAttachment: true);

        $this->actingAs($this->user)->postJson(route('outgoing.undo', $theirs->id))->assertNotFound();
        $this->actingAs($this->user)->deleteJson(route('outgoing.dismiss', $theirs->id))->assertNotFound();
        $this->actingAs($this->user)->get(route('compose.create', ['restore' => $theirs->id]))->assertNotFound();

        $this->assertSame(OutgoingMessage::HELD, $theirs->fresh()->status);
        Storage::disk('outgoing')->assertExists($theirs->attachments[0]['path']);
    }

    public function test_the_composer_reopens_with_everything_including_attachments(): void
    {
        $message = $this->hold(withAttachment: true);
        $this->actingAs($this->user)->postJson(route('outgoing.undo', $message->id))->assertOk();

        $this->actingAs($this->user)
            ->get(route('compose.create', ['restore' => $message->id]))
            ->assertOk()
            ->assertSee('friend@example.com')
            ->assertSee('boss@example.com')
            ->assertSee('Lunch')
            ->assertSee('Second line')
            ->assertSee('abc@mail.example.com')
            ->assertSee('one@x two@x')
            ->assertSee('menu.pdf')
            ->assertSee('name="restore_id" value="'.$message->id.'"', false)
            ->assertSee('name="draft_id" id="draft_id" value="'.$message->draft_id.'"', false)
            ->assertSee('name="keep_attachments[]"', false);

        Storage::disk('outgoing')->assertExists($message->attachments[0]['path']);
    }

    public function test_a_message_still_being_held_or_already_sent_cannot_be_reopened(): void
    {
        $held = $this->hold();

        $this->actingAs($this->user)->get(route('compose.create', ['restore' => $held->id]))->assertNotFound();
    }

    public function test_sending_the_reopened_composer_carries_chosen_attachments_over(): void
    {
        $old = $this->hold(withAttachment: true);
        $oldPath = $old->attachments[0]['path'];
        $this->actingAs($this->user)->postJson(route('outgoing.undo', $old->id))->assertOk();

        $this->actingAs($this->user)->post(route('compose.store'), [
            'mail_account_id' => $this->account->id,
            'to' => 'friend@example.com',
            'subject' => 'Lunch, corrected',
            'body' => 'Noon!',
            'restore_id' => $old->id,
            'keep_attachments' => [0],
        ])->assertSessionHasNoErrors();

        $new = OutgoingMessage::latest('id')->firstOrFail();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('menu.pdf', $new->attachments[0]['name']);
        Storage::disk('outgoing')->assertExists($new->attachments[0]['path']);
        Storage::disk('outgoing')->assertMissing($oldPath);
        $this->assertNull(OutgoingMessage::find($old->id));
    }

    public function test_reopening_the_same_message_twice_only_sends_it_once(): void
    {
        $old = $this->hold(withAttachment: true);
        $this->actingAs($this->user)->postJson(route('outgoing.undo', $old->id))->assertOk();

        $form = [
            'mail_account_id' => $this->account->id,
            'to' => 'friend@example.com',
            'subject' => 'Again',
            'body' => 'x',
            'restore_id' => $old->id,
            'keep_attachments' => [0],
        ];

        $this->actingAs($this->user)->post(route('compose.store'), $form)->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('compose.store'), $form)->assertSessionHasErrors('restore_id');

        $this->assertSame(1, OutgoingMessage::count());
    }

    public function test_a_kept_attachment_that_has_gone_missing_is_an_error_not_a_silent_drop(): void
    {
        $old = $this->hold(withAttachment: true);
        $this->actingAs($this->user)->postJson(route('outgoing.undo', $old->id))->assertOk();
        Storage::disk('outgoing')->delete($old->attachments[0]['path']);

        $this->actingAs($this->user)->post(route('compose.store'), [
            'mail_account_id' => $this->account->id,
            'to' => 'friend@example.com',
            'subject' => 'Again',
            'body' => 'x',
            'restore_id' => $old->id,
            'keep_attachments' => [0],
        ])->assertSessionHasErrors('keep_attachments');

        $this->assertSame(OutgoingMessage::CANCELLED, $old->fresh()->status);
        $this->assertSame(1, OutgoingMessage::count());
    }

    public function test_attachments_left_unchecked_are_deleted_when_the_reopened_composer_is_sent(): void
    {
        $old = $this->hold(withAttachment: true);
        $oldPath = $old->attachments[0]['path'];
        $this->actingAs($this->user)->postJson(route('outgoing.undo', $old->id))->assertOk();

        $this->actingAs($this->user)->post(route('compose.store'), [
            'mail_account_id' => $this->account->id,
            'to' => 'friend@example.com',
            'subject' => 'Lunch',
            'body' => 'Noon!',
            'restore_id' => $old->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame([], OutgoingMessage::latest('id')->firstOrFail()->attachments);
        Storage::disk('outgoing')->assertMissing($oldPath);
    }

    public function test_someone_elses_restore_id_cannot_pull_in_their_attachments(): void
    {
        $other = User::factory()->create();
        MailAccount::factory()->create(['user_id' => $other->id]);
        $theirs = $this->hold($other, withAttachment: true);
        $this->actingAs($other)->postJson(route('outgoing.undo', $theirs->id))->assertOk();

        $this->actingAs($this->user)->post(route('compose.store'), [
            'mail_account_id' => $this->account->id,
            'to' => 'friend@example.com',
            'subject' => 'Sneaky',
            'body' => 'x',
            'restore_id' => $theirs->id,
            'keep_attachments' => [0],
        ])->assertSessionHasErrors('restore_id');

        $this->assertSame(0, OutgoingMessage::where('user_id', $this->user->id)->count());
        Storage::disk('outgoing')->assertExists($theirs->attachments[0]['path']);
        $this->assertNotNull(OutgoingMessage::find($theirs->id));
    }

    public function test_dismissing_a_failed_message_deletes_it_and_its_attachments(): void
    {
        $this->fakeSmtp('boom');
        $message = $this->hold(withAttachment: true);
        $path = $message->attachments[0]['path'];
        $this->travel(11)->seconds();
        $this->runJob($message);

        $this->actingAs($this->user)->deleteJson(route('outgoing.dismiss', $message->id))->assertNoContent();

        $this->assertNull(OutgoingMessage::find($message->id));
        Storage::disk('outgoing')->assertMissing($path);
    }

    public function test_a_message_still_in_flight_cannot_be_dismissed(): void
    {
        $message = $this->hold();

        $this->actingAs($this->user)->deleteJson(route('outgoing.dismiss', $message->id))->assertStatus(409);

        $this->assertNotNull(OutgoingMessage::find($message->id));
    }
}
