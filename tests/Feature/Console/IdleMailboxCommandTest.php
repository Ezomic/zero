<?php

namespace Tests\Feature\Console;

use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdleMailboxCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_exits_cleanly_when_account_no_longer_exists(): void
    {
        $this->artisan('mail:idle', ['account' => 999999])
            ->expectsOutputToContain('no longer exists')
            ->assertExitCode(0);
    }

    public function test_exits_cleanly_when_account_is_inactive(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create([
            'user_id' => $user->id,
            'is_active' => false,
        ]);

        $this->artisan('mail:idle', ['account' => $account->id])
            ->expectsOutputToContain('inactive')
            ->assertExitCode(0);
    }
}
