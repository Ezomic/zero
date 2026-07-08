<?php

namespace Tests\Feature\Auth;

use App\Mail\LoginCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SecurityConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_managing_passkeys_requires_confirmation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/user/passkeys/options');

        $response->assertStatus(423);
    }

    public function test_confirming_identity_with_the_correct_code_allows_managing_passkeys(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/confirm-password/send');

        $sentCode = null;
        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$sentCode) {
            $sentCode = $mail->code;

            return true;
        });

        $this->actingAs($user)->post('/confirm-password', ['code' => $sentCode]);

        $response = $this->actingAs($user)->getJson('/user/passkeys/options');

        $response->assertStatus(200);
    }

    public function test_confirming_identity_with_the_wrong_code_fails(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/confirm-password/send');

        $response = $this->actingAs($user)->post('/confirm-password', ['code' => '000000']);

        $response->assertSessionHasErrors('code');

        $optionsResponse = $this->actingAs($user)->getJson('/user/passkeys/options');
        $optionsResponse->assertStatus(423);
    }
}
