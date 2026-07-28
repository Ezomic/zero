<?php

namespace Tests\Feature\Auth;

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
