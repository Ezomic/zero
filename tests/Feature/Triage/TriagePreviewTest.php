<?php

namespace Tests\Feature\Triage;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TriagePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_body_is_isolated_in_a_script_less_sandboxed_iframe(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        Email::factory()->create([
            'mail_account_id' => $account->id,
            'body_html' => '<p>Hi</p><img src=x onerror="alert(document.cookie)">',
        ]);

        $response = $this->actingAs($user)->get(route('triage.index'));

        $response->assertOk();
        $response->assertDontSee('<img src=x onerror=', false);
        $response->assertSee('srcdoc="&lt;p&gt;Hi&lt;/p&gt;&lt;img src=x onerror=&quot;alert(document.cookie)&quot;&gt;"', false);
        $response->assertSee('sandbox="allow-same-origin"', false);
    }

    public function test_text_body_is_escaped_when_there_is_no_html(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        Email::factory()->create([
            'mail_account_id' => $account->id,
            'body_html' => null,
            'body_text' => '<script>alert(1)</script>',
        ]);

        $response = $this->actingAs($user)->get(route('triage.index'));

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
