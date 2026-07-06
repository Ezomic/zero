<?php

namespace Tests\Feature\Accounts;

use App\Jobs\SyncMailAccountJob;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReenableTest extends TestCase
{
    use RefreshDatabase;

    public function test_reactivates_account_and_queues_a_sync(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create([
            'user_id' => $user->id,
            'is_active' => false,
            'sync_status' => 'error',
            'sync_error' => 'NO AUTHENTICATE failed.',
        ]);

        $response = $this->actingAs($user)->post(route('accounts.reenable', $account));

        $response->assertRedirect();
        $account->refresh();
        $this->assertTrue($account->is_active);
        $this->assertSame('idle', $account->sync_status);
        $this->assertNull($account->sync_error);
        Queue::assertPushed(SyncMailAccountJob::class);
    }

    public function test_cannot_reenable_another_users_account(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['is_active' => false]);

        $this->actingAs($user)
            ->post(route('accounts.reenable', $account))
            ->assertForbidden();
    }
}
