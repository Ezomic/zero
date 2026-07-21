<?php

namespace Tests\Feature\Triage;

use App\Jobs\ApplyEmailFlagJob;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TriageMutationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: MailAccount}
     */
    private function userWithAccount(): array
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        return [$user, $account];
    }

    public function test_delete_soft_deletes_the_whole_thread_and_queues_jobs(): void
    {
        Queue::fake();
        [$user, $account] = $this->userWithAccount();
        $messages = Email::factory()->count(2)->create([
            'mail_account_id' => $account->id,
            'thread_id' => 't1',
            'is_deleted' => false,
        ]);

        $this->actingAs($user)
            ->post(route('triage.delete', $messages->first()))
            ->assertRedirect(route('triage.index', ['account' => $account->id]));

        foreach ($messages as $message) {
            $this->assertTrue($message->refresh()->is_deleted);
        }
        Queue::assertPushed(ApplyEmailFlagJob::class, 2);
    }

    public function test_skip_records_the_thread_in_the_session_and_does_not_touch_the_message(): void
    {
        [$user, $account] = $this->userWithAccount();
        $email = Email::factory()->create([
            'mail_account_id' => $account->id,
            'thread_id' => 'thread-xyz',
            'is_deleted' => false,
        ]);

        $response = $this->actingAs($user)
            ->post(route('triage.skip', $email));

        $response->assertRedirect(route('triage.index', ['account' => $account->id]));
        $response->assertSessionHas("triage_skipped.{$account->id}", ['thread-xyz']);
        $this->assertFalse($email->refresh()->is_deleted);
    }

    public function test_skip_deduplicates_repeated_skips_of_the_same_thread(): void
    {
        [$user, $account] = $this->userWithAccount();
        $email = Email::factory()->create([
            'mail_account_id' => $account->id,
            'thread_id' => 'thread-xyz',
        ]);

        $this->actingAs($user)->post(route('triage.skip', $email));
        $response = $this->actingAs($user)
            ->withSession(["triage_skipped.{$account->id}" => ['thread-xyz']])
            ->post(route('triage.skip', $email));

        $response->assertSessionHas("triage_skipped.{$account->id}", ['thread-xyz']);
    }

    public function test_a_user_cannot_triage_another_users_email(): void
    {
        [, $account] = $this->userWithAccount();
        $email = Email::factory()->create(['mail_account_id' => $account->id]);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->post(route('triage.delete', $email))
            ->assertForbidden();
    }
}
