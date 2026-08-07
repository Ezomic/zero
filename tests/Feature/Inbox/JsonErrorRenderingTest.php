<?php

namespace Tests\Feature\Inbox;

use App\Models\Draft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rendered exceptions have to come back in the format the caller asked for.
 * Restricting that to api/* left the XHR endpoints that live elsewhere
 * (drafts.autosave, contacts.search) receiving HTML redirects (ZERO-112).
 */
class JsonErrorRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_json_request_to_a_non_api_route_gets_a_json_validation_error(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('drafts.autosave'), [
                'subject' => str_repeat('x', 300), // max:255
                'body' => 'x',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subject');

        $this->assertSame(0, Draft::count());
    }

    /**
     * The compose page reads this response with r.json(), so an HTML body
     * here is a parse error in the browser and a draft that stops saving
     * with nothing shown to the user.
     */
    public function test_the_autosave_response_is_parseable_json_when_it_fails(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('drafts.autosave'), ['subject' => str_repeat('x', 300), 'body' => 'x']);

        $this->assertJson($response->getContent() ?: '');
        $this->assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
    }

    public function test_an_ordinary_form_post_still_redirects_back_with_errors(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('compose.store'), ['to' => '', 'subject' => '', 'body' => ''])
            ->assertRedirect()
            ->assertSessionHasErrors(['mail_account_id', 'to', 'subject', 'body']);
    }

    public function test_api_routes_keep_answering_in_json(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('inbox.unreadCount'))
            ->assertOk()
            ->assertJsonStructure(['unread']);
    }

    public function test_a_guest_asking_for_json_is_told_so_in_json(): void
    {
        $this->postJson(route('drafts.autosave'), ['subject' => 'x', 'body' => 'x'])
            ->assertStatus(401)
            ->assertJsonStructure(['message']);
    }
}
