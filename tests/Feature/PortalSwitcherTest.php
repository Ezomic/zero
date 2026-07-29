<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalSwitcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.thijssensoftware', [
            'base_url' => 'https://id.example.test',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'slug' => 'zero',
            'portal_cache_ttl' => 300,
        ]);
    }

    private function fakeIdPortal(): void
    {
        Http::fake([
            'id.example.test/oauth/token' => Http::response(['access_token' => 'token', 'expires_in' => 900]),
            'id.example.test/api/portal/apps' => Http::response(['applications' => [
                ['slug' => 'billr', 'name' => 'Billr', 'initials' => 'B', 'accent' => '#3B82F6', 'launch_url' => 'https://billr.test'],
                ['slug' => 'zero', 'name' => 'Zero', 'initials' => 'Z', 'accent' => '#EF6A5E', 'launch_url' => 'https://zero.test'],
            ]]),
        ]);
    }

    public function test_the_switcher_renders_in_the_app_shell(): void
    {
        $this->fakeIdPortal();

        $response = $this->actingAs(User::factory()->create())->get(route('accounts.index'));

        $response->assertOk();
        $response->assertSee('Your apps');
        $response->assertSee('Billr');
        $response->assertSee('https://billr.test', false);
    }

    public function test_the_current_app_is_not_a_link_to_itself(): void
    {
        $this->fakeIdPortal();

        $response = $this->actingAs(User::factory()->create())->get(route('accounts.index'));

        // The tile for the app you're already in is inert: aria-current, href="#".
        $response->assertSee('aria-current="page"', false);
        $response->assertSee('href="#"', false);
    }

    public function test_the_shell_still_renders_when_id_is_unreachable(): void
    {
        Http::fake([
            'id.example.test/*' => Http::response([], 500),
        ]);

        $response = $this->actingAs(User::factory()->create())->get(route('accounts.index'));

        $response->assertOk();
        $response->assertDontSee('Your apps');
    }
}
