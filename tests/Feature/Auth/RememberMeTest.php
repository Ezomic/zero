<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class RememberMeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Signs in through a faked ID and hands back the remember-me cookie exactly
     * as the browser received it.
     *
     * @return array{string, string}
     */
    private function signInThroughId(): array
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn((new SocialiteUser)->setRaw([
            'sub' => '42',
            'name' => 'Robbin Thijssen',
            'email' => 'robbin@example.com',
            'applications' => ['zero'],
        ])->map([
            'id' => '42',
            'name' => 'Robbin Thijssen',
            'email' => 'robbin@example.com',
        ]));
        Socialite::shouldReceive('driver')->with('thijssensoftware')->andReturn($provider);

        $name = Auth::guard('web')->getRecallerName();
        $cookie = $this->get(route('sso.callback'))->assertRedirect('/')->getCookie($name, decrypt: false);

        $this->assertNotNull($cookie);

        return [$name, (string) $cookie->getValue()];
    }

    /**
     * What the browser sends once its session has expired: no session, only
     * the remember-me cookie.
     */
    private function returnWithOnlyTheRememberCookie(string $name, string $value): TestResponse
    {
        session()->flush();
        Auth::forgetGuards();

        return $this->withUnencryptedCookie($name, $value)->get(route('accounts.index'));
    }

    private function signOutAtId(User $user): TestResponse
    {
        config(['id-client.logout_secret' => 'test-logout-secret']);

        $body = (string) json_encode(['event' => 'logout', 'sub' => $user->idp_id, 'issued_at' => now()->getTimestamp()]);

        return $this->call('POST', route('sso.logout'), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ID_SIGNATURE' => hash_hmac('sha256', $body, 'test-logout-secret'),
        ], content: $body);
    }

    public function test_it_keeps_a_browser_signed_in_after_its_session_expires(): void
    {
        [$name, $value] = $this->signInThroughId();

        $this->returnWithOnlyTheRememberCookie($name, $value)->assertOk();

        $this->assertAuthenticatedAs(User::where('email', 'robbin@example.com')->firstOrFail());
    }

    public function test_it_refuses_the_remember_me_cookie_once_id_signs_the_user_out(): void
    {
        [$name, $value] = $this->signInThroughId();
        $this->returnWithOnlyTheRememberCookie($name, $value)->assertOk();

        $this->signOutAtId(User::where('email', 'robbin@example.com')->firstOrFail())->assertOk();

        $this->returnWithOnlyTheRememberCookie($name, $value)->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
