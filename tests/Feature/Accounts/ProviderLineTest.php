<?php

namespace Tests\Feature\Accounts;

use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_inactive_account_shows_a_real_separator_not_an_entity(): void
    {
        $user = User::factory()->create();
        MailAccount::factory()->create(['user_id' => $user->id, 'is_active' => false]);

        $response = $this->actingAs($user)->get(route('accounts.index'))->assertOk();

        $response->assertSee('· inactive');

        // The needle is escaped before matching, so this looks for "&amp;middot;"
        // — what an entity inside {{ }} actually produces. Entities written
        // directly into the markup elsewhere on this page are unaffected.
        $response->assertDontSee('&middot;');
    }

    public function test_an_active_account_shows_no_separator(): void
    {
        $user = User::factory()->create();
        MailAccount::factory()->create(['user_id' => $user->id, 'is_active' => true]);

        $this->actingAs($user)
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertDontSee('· inactive');
    }
}
