<?php

namespace Tests\Feature\Mail;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\PendingMirrorAction;
use App\Models\User;
use App\Services\Mail\ImapClientFactory;
use App\Services\Mail\ImapSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\Connection\Protocols\Response;

// Swaps in a pre-built client so the batching can be exercised without a live
// IMAP connection.
class TestableImapSyncServiceForBatch extends ImapSyncService
{
    public function __construct(private Client $client)
    {
        parent::__construct(new ImapClientFactory(Mockery::mock(OAuthTokenRefresher::class)));
    }

    protected function buildClient(MailAccount $account): Client
    {
        return $this->client;
    }
}

class ImapSyncServiceBatchActionsTest extends TestCase
{
    use RefreshDatabase;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->account = MailAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => MailAccount::PROVIDER_IMAP,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function response(bool $ok): Response&MockInterface
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('boolean')->andReturn($ok);

        return $response;
    }

    /** @return array{0: Client&MockInterface, 1: ProtocolInterface&MockInterface} */
    private function client(): array
    {
        $connection = Mockery::mock(ProtocolInterface::class);
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->andReturnSelf();
        $client->shouldReceive('getConnection')->andReturn($connection);

        return [$client, $connection];
    }

    private function queue(string $action, string $uid, string $path = 'INBOX'): PendingMirrorAction
    {
        $email = Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'folder' => 'INBOX',
            'uid' => $uid,
        ]);

        return PendingMirrorAction::create([
            'mail_account_id' => $this->account->id,
            'email_id' => $email->id,
            'action' => $action,
            'remote_folder_path' => $path,
            'uid' => $uid,
        ]);
    }

    public function test_contiguous_uids_collapse_into_one_store_command(): void
    {
        [$client, $connection] = $this->client();
        $connection->shouldReceive('selectFolder')->once()->with('INBOX');

        // The whole point of the ticket: three messages, one round-trip.
        $connection->shouldReceive('store')
            ->once()
            ->with(['\Seen'], 10, 12, '+')
            ->andReturn($this->response(true));

        $actions = collect(['10', '11', '12'])->map(fn ($uid) => $this->queue('mark_read', $uid));

        (new TestableImapSyncServiceForBatch($client))->applyPendingActions($this->account, $actions);

        $this->assertSame(0, PendingMirrorAction::count());
    }

    public function test_gaps_in_the_uid_set_become_separate_ranges(): void
    {
        [$client, $connection] = $this->client();
        $connection->shouldReceive('selectFolder')->once();

        $connection->shouldReceive('store')->once()->with(['\Seen'], 10, 11, '+')->andReturn($this->response(true));
        $connection->shouldReceive('store')->once()->with(['\Seen'], 20, null, '+')->andReturn($this->response(true));

        $actions = collect(['10', '11', '20'])->map(fn ($uid) => $this->queue('mark_read', $uid));

        (new TestableImapSyncServiceForBatch($client))->applyPendingActions($this->account, $actions);

        $this->assertSame(0, PendingMirrorAction::count());
    }

    public function test_deletes_go_out_as_one_move_and_one_expunge(): void
    {
        MailFolder::create([
            'mail_account_id' => $this->account->id,
            'local_name' => 'TRASH',
            'remote_path' => '[Gmail]/Trash',
        ]);

        [$client, $connection] = $this->client();
        $connection->shouldReceive('selectFolder')->once();

        // MOVE takes an arbitrary set, so a gap costs nothing here.
        $connection->shouldReceive('moveManyMessages')
            ->once()
            ->with(['5', '9'], '[Gmail]/Trash')
            ->andReturn($this->response(true));
        $connection->shouldReceive('expunge')->once();

        $actions = collect(['5', '9'])->map(fn ($uid) => $this->queue('delete', $uid));

        (new TestableImapSyncServiceForBatch($client))->applyPendingActions($this->account, $actions);

        $this->assertSame(0, PendingMirrorAction::count());
    }

    public function test_a_failed_range_retries_each_uid_so_one_bad_message_does_not_sink_the_batch(): void
    {
        [$client, $connection] = $this->client();
        $connection->shouldReceive('selectFolder')->once();

        $connection->shouldReceive('store')->once()->with(['\Seen'], 10, 12, '+')->andReturn($this->response(false));
        $connection->shouldReceive('store')->once()->with(['\Seen'], 10, null, '+')->andReturn($this->response(true));
        $connection->shouldReceive('store')->once()->with(['\Seen'], 11, null, '+')->andReturn($this->response(false));
        $connection->shouldReceive('store')->once()->with(['\Seen'], 12, null, '+')->andReturn($this->response(true));

        $actions = collect(['10', '11', '12'])->map(fn ($uid) => $this->queue('mark_read', $uid));

        (new TestableImapSyncServiceForBatch($client))->applyPendingActions($this->account, $actions);

        $remaining = PendingMirrorAction::sole();
        $this->assertSame('11', $remaining->uid);
        $this->assertSame(1, $remaining->attempts);
        $this->assertNull($remaining->failed_at);
    }

    public function test_each_folder_gets_its_own_select(): void
    {
        [$client, $connection] = $this->client();

        $connection->shouldReceive('selectFolder')->once()->with('INBOX');
        $connection->shouldReceive('selectFolder')->once()->with('Receipts');
        $connection->shouldReceive('store')->twice()->andReturn($this->response(true));

        $actions = collect([
            $this->queue('mark_read', '10', 'INBOX'),
            $this->queue('mark_read', '77', 'Receipts'),
        ]);

        (new TestableImapSyncServiceForBatch($client))->applyPendingActions($this->account, $actions);

        $this->assertSame(0, PendingMirrorAction::count());
    }

    public function test_an_unselectable_folder_fails_only_its_own_actions(): void
    {
        [$client, $connection] = $this->client();

        $connection->shouldReceive('selectFolder')->with('INBOX')->andThrow(new \RuntimeException('no such folder'));
        $connection->shouldReceive('selectFolder')->with('Receipts');
        $connection->shouldReceive('store')->once()->with(['\Seen'], 77, null, '+')->andReturn($this->response(true));

        $inbox = $this->queue('mark_read', '10', 'INBOX');
        $receipts = $this->queue('mark_read', '77', 'Receipts');

        (new TestableImapSyncServiceForBatch($client))->applyPendingActions($this->account, collect([$inbox, $receipts]));

        $this->assertNull($receipts->fresh());
        $this->assertSame('no such folder', $inbox->fresh()->last_error);
    }

    public function test_an_action_is_abandoned_after_three_attempts(): void
    {
        $action = $this->queue('mark_read', '10');

        foreach (range(1, 3) as $attempt) {
            [$client, $connection] = $this->client();
            $connection->shouldReceive('selectFolder')->once();
            $connection->shouldReceive('store')->andReturn($this->response(false));

            (new TestableImapSyncServiceForBatch($client))->applyPendingActions(
                $this->account,
                PendingMirrorAction::query()->pending()->get(),
            );
        }

        $action->refresh();
        $this->assertSame(3, $action->attempts);
        $this->assertNotNull($action->failed_at);
        $this->assertSame(0, PendingMirrorAction::query()->pending()->count());
    }
}
