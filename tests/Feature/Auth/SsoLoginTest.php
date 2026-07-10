<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;
use Thijssensoftware\IdClient\Exceptions\AccessDeniedException;

class SsoLoginTest extends TestCase
{
    use RefreshDatabase;

    private function fakeIdUser(): SocialiteUser
    {
        return (new SocialiteUser)->setRaw([
            'sub' => '42',
            'name' => 'Robbin Thijssen',
            'email' => 'robbin@example.com',
            'applications' => ['zero'],
        ])->map([
            'id' => '42',
            'name' => 'Robbin Thijssen',
            'email' => 'robbin@example.com',
        ]);
    }

    private function mockSocialite(callable $configure): void
    {
        $provider = Mockery::mock(Provider::class);
        $configure($provider);

        Socialite::shouldReceive('driver')->with('thijssensoftware')->andReturn($provider);
    }

    public function test_the_redirect_route_starts_the_sso_flow(): void
    {
        $this->mockSocialite(fn ($provider) => $provider->shouldReceive('redirect')->andReturn(redirect('https://id.test/oauth/authorize')));

        $this->get(route('sso.redirect'))->assertRedirect('https://id.test/oauth/authorize');
    }

    public function test_it_provisions_and_logs_in_a_new_user(): void
    {
        $this->mockSocialite(fn ($provider) => $provider->shouldReceive('user')->andReturn($this->fakeIdUser()));

        $this->get(route('sso.callback'))->assertRedirect('/');

        $this->assertAuthenticated();
        $user = User::where('email', 'robbin@example.com')->firstOrFail();
        $this->assertSame('42', $user->idp_id);
        $this->assertSame('Robbin Thijssen', $user->name);
    }

    public function test_it_links_an_existing_user_by_email(): void
    {
        $existing = User::factory()->create(['email' => 'robbin@example.com', 'idp_id' => null]);

        $this->mockSocialite(fn ($provider) => $provider->shouldReceive('user')->andReturn($this->fakeIdUser()));

        $this->get(route('sso.callback'))->assertRedirect('/');

        $this->assertAuthenticatedAs($existing->fresh());
        $this->assertSame('42', $existing->fresh()->idp_id);
        $this->assertSame(1, User::count());
    }

    public function test_it_denies_a_user_without_access(): void
    {
        $this->mockSocialite(fn ($provider) => $provider->shouldReceive('user')->andThrow(new AccessDeniedException('nope')));

        $this->get(route('sso.callback'))->assertForbidden();

        $this->assertGuest();
    }
}
