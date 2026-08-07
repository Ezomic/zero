<?php

namespace Tests\Feature\Inbox;

use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * features.realtime_inbox is documented as the way to fall back to polling
 * when Reverb is not running. It was defined and then never read anywhere, so
 * turning it off changed nothing: the client was still built and the channel
 * still subscribed (ZERO-110).
 */
class RealtimeToggleTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        MailAccount::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    public function test_the_flag_reaches_the_browser_when_realtime_is_on(): void
    {
        config(['features.realtime_inbox' => true]);

        $this->actingAs($this->actingUser())
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertSee('window.zeroRealtimeInbox = true', false);
    }

    public function test_the_flag_reaches_the_browser_as_false_when_realtime_is_off(): void
    {
        config(['features.realtime_inbox' => false]);

        $this->actingAs($this->actingUser())
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertSee('window.zeroRealtimeInbox = false', false);
    }

    public function test_the_channel_subscription_is_not_rendered_when_realtime_is_off(): void
    {
        config(['features.realtime_inbox' => false]);

        $this->actingAs($this->actingUser())
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertDontSee('Echo.private', false)
            ->assertDontSee('.new-email', false);
    }

    public function test_the_channel_subscription_is_rendered_when_realtime_is_on(): void
    {
        config(['features.realtime_inbox' => true]);

        $this->actingAs($this->actingUser())
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertSee('Echo.private', false);
    }

    /**
     * The badge is the fallback when realtime is off, so it must keep working
     * in both cases.
     */
    public function test_the_unread_badge_poll_survives_either_setting(): void
    {
        foreach ([true, false] as $enabled) {
            config(['features.realtime_inbox' => $enabled]);

            $this->actingAs($this->actingUser())
                ->get(route('inbox.index'))
                ->assertOk()
                ->assertSee(route('inbox.unreadCount'), false);
        }
    }
}
