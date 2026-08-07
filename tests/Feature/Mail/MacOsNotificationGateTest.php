<?php

namespace Tests\Feature\Mail;

use App\Services\Mail\ImapClientFactory;
use App\Services\Mail\ImapSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Mockery;
use Tests\TestCase;

/**
 * Records whether the notification would have fired, and on which platform,
 * without ever shelling out.
 */
class RecordingImapSyncService extends ImapSyncService
{
    public int $notifications = 0;

    public function __construct(ImapClientFactory $clients, private string $family)
    {
        parent::__construct($clients);
    }

    protected function osFamily(): string
    {
        return $this->family;
    }

    protected function notifyMacOs(string $from, string $subject): void
    {
        $this->notifications++;
    }

    public function wouldNotify(): bool
    {
        return $this->macOsNotificationsEnabled();
    }
}

class MacOsNotificationGateTest extends TestCase
{
    private function service(string $family): RecordingImapSyncService
    {
        return new RecordingImapSyncService(
            new ImapClientFactory(Mockery::mock(OAuthTokenRefresher::class)),
            $family,
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    /**
     * The regression: the flag defaults to on and production's .env does not
     * set it, so every new message forked an osascript that cannot exist on
     * Linux.
     */
    public function test_it_never_fires_off_darwin_even_with_the_flag_on(): void
    {
        config(['features.macos_notifications' => true]);

        $this->assertFalse($this->service('Linux')->wouldNotify());
        $this->assertFalse($this->service('Windows')->wouldNotify());
    }

    public function test_it_fires_on_darwin_when_the_flag_is_on(): void
    {
        config(['features.macos_notifications' => true]);

        $this->assertTrue($this->service('Darwin')->wouldNotify());
    }

    public function test_the_flag_still_turns_it_off_on_a_mac(): void
    {
        config(['features.macos_notifications' => false]);

        $this->assertFalse($this->service('Darwin')->wouldNotify());
    }

    public function test_the_default_config_leaves_it_on_for_mac_users(): void
    {
        // Nothing here should quietly disable local notifications while
        // fixing the production waste.
        $this->assertTrue((bool) config('features.macos_notifications'));
    }
}
