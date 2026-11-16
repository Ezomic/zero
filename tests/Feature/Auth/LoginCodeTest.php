<?php

namespace Tests\Feature\Auth;

use App\Actions\Auth\SendLoginCode;
use App\Actions\Auth\VerifyLoginCode;
use App\Mail\LoginCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LoginCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_the_landing_page_at_root(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('One inbox for every account.', false);
    }

    public function test_guest_is_redirected_to_login_from_a_protected_route(): void
    {
        $response = $this->get('/triage');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_gets_the_inbox_at_root_not_the_landing(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertDontSee('One inbox for every account.', false);
    }

    public function test_authenticated_user_can_log_out(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_sending_a_login_code_emails_the_matching_user(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $response = $this->post('/login/code', ['email' => $user->email]);

        $response->assertRedirect(route('login.code.challenge'));
        Mail::assertSent(LoginCodeMail::class);
    }

    public function test_sending_a_login_code_for_an_unknown_email_does_not_reveal_that(): void
    {
        Mail::fake();

        $response = $this->post('/login/code', ['email' => 'nobody@example.com']);

        $response->assertRedirect(route('login.code.challenge'));
        Mail::assertNothingSent();
    }

    public function test_verifying_the_correct_code_logs_the_user_in(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->post('/login/code', ['email' => $user->email]);

        $sentCode = null;
        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$sentCode) {
            $sentCode = $mail->code;

            return true;
        });

        $response = $this->post('/login/code/verify', [
            'email' => $user->email,
            'code' => $sentCode,
        ]);

        $response->assertRedirect(route('inbox.index'));
        $this->assertAuthenticated();
    }

    public function test_verifying_an_expired_code_fails(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->post('/login/code', ['email' => $user->email]);

        $sentCode = null;
        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$sentCode) {
            $sentCode = $mail->code;

            return true;
        });

        $this->travel(11)->minutes();

        $response = $this->post('/login/code/verify', [
            'email' => $user->email,
            'code' => $sentCode,
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_login_code_is_single_use(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->post('/login/code', ['email' => $user->email]);

        $sentCode = null;
        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$sentCode) {
            $sentCode = $mail->code;

            return true;
        });

        $this->post('/login/code/verify', ['email' => $user->email, 'code' => $sentCode]);
        $this->post('/logout');

        $response = $this->post('/login/code/verify', ['email' => $user->email, 'code' => $sentCode]);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    private function issueCode(User $user): string
    {
        Mail::fake();
        app(SendLoginCode::class)->handle($user);

        $sentCode = '';
        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$sentCode) {
            $sentCode = $mail->code;

            return true;
        });

        return $sentCode;
    }

    private function wrongCode(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    public function test_a_code_is_wiped_after_five_failed_attempts(): void
    {
        $user = User::factory()->create();
        $code = $this->issueCode($user);
        $verify = app(VerifyLoginCode::class);

        for ($i = 0; $i < VerifyLoginCode::MAX_ATTEMPTS; $i++) {
            $this->assertFalse($verify->handle($user, $this->wrongCode($code)));
        }

        $this->assertFalse($verify->handle($user, $code));
        $this->assertNull($user->refresh()->login_code_hash);
    }

    public function test_the_right_code_still_works_after_fewer_than_five_misses(): void
    {
        $user = User::factory()->create();
        $code = $this->issueCode($user);
        $verify = app(VerifyLoginCode::class);

        for ($i = 0; $i < VerifyLoginCode::MAX_ATTEMPTS - 1; $i++) {
            $verify->handle($user, $this->wrongCode($code));
        }

        $this->assertTrue($verify->handle($user, $code));
    }

    public function test_a_new_code_starts_with_a_fresh_attempt_count(): void
    {
        $user = User::factory()->create();
        $first = $this->issueCode($user);
        $verify = app(VerifyLoginCode::class);

        for ($i = 0; $i < VerifyLoginCode::MAX_ATTEMPTS; $i++) {
            $verify->handle($user, $this->wrongCode($first));
        }

        $second = $this->issueCode($user);

        $this->assertSame(0, $user->refresh()->login_code_attempts);
        $this->assertTrue($verify->handle($user, $second));
    }

    public function test_guesses_for_one_email_are_throttled_across_ips(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
                ->post('/login/code/verify', ['email' => $user->email, 'code' => '000000'])
                ->assertSessionHasErrors('code');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->post('/login/code/verify', ['email' => strtoupper($user->email), 'code' => '000000'])
            ->assertTooManyRequests();
    }

    public function test_verifying_a_wrong_code_fails(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login/code/verify', [
            'email' => $user->email,
            'code' => '000000',
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }
}
