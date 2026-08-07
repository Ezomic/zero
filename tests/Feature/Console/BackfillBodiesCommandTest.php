<?php

namespace Tests\Feature\Console;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use App\Services\Mail\GraphMailSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BackfillBodiesCommandTest extends TestCase
{
    use RefreshDatabase;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = MailAccount::factory()->create([
            'user_id' => User::factory(),
            'provider' => MailAccount::PROVIDER_OUTLOOK,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function bodyless(array $attributes = []): Email
    {
        return Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'folder' => 'INBOX',
            'uid' => (string) fake()->unique()->numberBetween(1000, 99999),
            'body_text' => null,
            'body_html' => null,
            'is_deleted' => false,
            ...$attributes,
        ]);
    }

    /** Stands in for the network, writing the body the real service would. */
    private function fakeGraphFetching(?callable $onFetch = null): void
    {
        $graph = Mockery::mock(GraphMailSyncService::class);
        $graph->shouldReceive('fetchBody')->andReturnUsing(function (Email $email) use ($onFetch) {
            if ($onFetch) {
                $onFetch($email);
            }

            $email->update(['body_text' => 'fetched body for '.$email->uid]);
        });

        $this->app->instance(GraphMailSyncService::class, $graph);
    }

    public function test_it_fetches_bodies_that_are_missing(): void
    {
        $email = $this->bodyless();
        $this->fakeGraphFetching();

        $this->artisan('mail:backfill-bodies')
            ->expectsOutputToContain('Fetched 1 bodies')
            ->assertExitCode(0);

        $this->assertNotNull($email->refresh()->body_text);
    }

    public function test_it_leaves_messages_that_already_have_a_body(): void
    {
        $already = $this->bodyless(['body_text' => 'already here']);
        $this->fakeGraphFetching();

        $this->artisan('mail:backfill-bodies')
            ->expectsOutputToContain('Nothing to backfill')
            ->assertExitCode(0);

        $this->assertSame('already here', $already->refresh()->body_text);
    }

    public function test_it_skips_deleted_messages(): void
    {
        $this->bodyless(['is_deleted' => true]);
        $this->fakeGraphFetching();

        $this->artisan('mail:backfill-bodies')
            ->expectsOutputToContain('Nothing to backfill')
            ->assertExitCode(0);
    }

    public function test_it_skips_inactive_accounts(): void
    {
        $this->account->forceFill(['is_active' => false])->save();
        $this->bodyless();
        $this->fakeGraphFetching();

        $this->artisan('mail:backfill-bodies')
            ->expectsOutputToContain('Nothing to backfill')
            ->assertExitCode(0);
    }

    /**
     * A stale uid or a message gone from the server must not stop the run.
     */
    public function test_one_failure_does_not_abandon_the_rest(): void
    {
        $doomed = $this->bodyless(['uid' => '1']);
        $this->bodyless(['uid' => '2']);
        $this->bodyless(['uid' => '3']);

        $this->fakeGraphFetching(function (Email $email) use ($doomed) {
            if ($email->id === $doomed->id) {
                throw new \RuntimeException('no headers found');
            }
        });

        $this->artisan('mail:backfill-bodies')
            ->expectsOutputToContain('Fetched 2 bodies, 1 skipped')
            ->assertExitCode(0);
    }

    public function test_the_limit_bounds_one_run(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->bodyless();
        }

        $this->fakeGraphFetching();

        $this->artisan('mail:backfill-bodies', ['--limit' => 2])
            ->expectsOutputToContain('Fetched 2 bodies, 0 skipped, 3 still without one.')
            ->assertExitCode(0);

        $this->assertSame(3, Email::whereNull('body_text')->whereNull('body_html')->count());
    }

    public function test_it_can_be_restricted_to_one_account(): void
    {
        $mine = $this->bodyless();

        $other = MailAccount::factory()->create(['user_id' => User::factory(), 'provider' => MailAccount::PROVIDER_OUTLOOK]);
        $theirs = Email::factory()->create([
            'mail_account_id' => $other->id,
            'uid' => '777',
            'body_text' => null,
            'body_html' => null,
            'is_deleted' => false,
        ]);

        $this->fakeGraphFetching();

        $this->artisan('mail:backfill-bodies', ['--account' => $this->account->id])->assertExitCode(0);

        $this->assertNotNull($mine->refresh()->body_text);
        $this->assertNull($theirs->refresh()->body_text);
    }

    /**
     * Newest first: a large backlog may never fully drain, and recent mail is
     * what someone is most likely to search for.
     */
    public function test_it_takes_the_newest_messages_first(): void
    {
        $older = $this->bodyless();
        $newer = $this->bodyless();

        $this->fakeGraphFetching();

        $this->artisan('mail:backfill-bodies', ['--limit' => 1])->assertExitCode(0);

        $this->assertNotNull($newer->refresh()->body_text);
        $this->assertNull($older->refresh()->body_text);
    }
}
