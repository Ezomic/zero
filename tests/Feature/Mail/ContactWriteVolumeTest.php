<?php

namespace Tests\Feature\Mail;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contact::remember() runs for every From, To and CC on every message both
 * sync services store. It used to do a firstOrNew plus an unconditional
 * save(), and since last_seen_at was always reassigned the model was always
 * dirty, so every sighting was a real write: roughly 20,000 statements to
 * maintain 479 rows on production (ZERO-107).
 */
class ContactWriteVolumeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Contact::forgetHandledThisRun();
    }

    /** @return array{writes: int, reads: int} */
    private function countQueries(callable $work): array
    {
        $writes = 0;
        $reads = 0;

        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($log as $entry) {
            $sql = strtolower((string) ($entry['query'] ?? ''));

            if (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update')) {
                $writes++;
            } elseif (str_starts_with($sql, 'select')) {
                $reads++;
            }
        }

        return ['writes' => $writes, 'reads' => $reads];
    }

    public function test_a_new_address_is_written_once(): void
    {
        $result = $this->countQueries(function () {
            Contact::remember($this->user->id, 'sender@example.com', 'Sender');
        });

        $this->assertSame(1, $result['writes']);
        $this->assertSame('Sender', Contact::sole()->name);
    }

    /**
     * The shape that mattered: one sender across a batch of messages.
     */
    public function test_the_same_sender_across_many_messages_costs_one_write(): void
    {
        $result = $this->countQueries(function () {
            for ($i = 0; $i < 200; $i++) {
                Contact::remember($this->user->id, 'newsletter@example.com', 'Newsletter');
            }
        });

        $this->assertSame(1, $result['writes'], '200 sightings must not be 200 writes');
        $this->assertSame(1, $result['reads'], 'nor 200 reads');
        $this->assertSame(1, Contact::count());
    }

    public function test_a_second_sync_run_does_not_rewrite_a_fresh_contact(): void
    {
        Contact::remember($this->user->id, 'sender@example.com', 'Sender');

        Contact::forgetHandledThisRun();

        $result = $this->countQueries(function () {
            Contact::remember($this->user->id, 'sender@example.com', 'Sender');
        });

        $this->assertSame(0, $result['writes']);
    }

    public function test_a_stale_contact_is_refreshed(): void
    {
        Contact::remember($this->user->id, 'sender@example.com', 'Sender');
        Contact::sole()->forceFill(['last_seen_at' => now()->subDays(3)])->save();

        Contact::forgetHandledThisRun();
        Contact::remember($this->user->id, 'sender@example.com', 'Sender');

        $this->assertTrue(Contact::sole()->last_seen_at->isToday());
    }

    /**
     * Autocomplete is the point of the table, so a genuinely new address has
     * to appear straight away rather than waiting for a staleness window.
     */
    public function test_a_new_address_still_appears_immediately(): void
    {
        Contact::remember($this->user->id, 'known@example.com', 'Known');
        Contact::remember($this->user->id, 'brand-new@example.com', 'Brand New');

        $this->assertEqualsCanonicalizing(
            ['known@example.com', 'brand-new@example.com'],
            Contact::pluck('email')->all(),
        );
    }

    public function test_a_name_arriving_later_fills_in_a_nameless_contact(): void
    {
        Contact::remember($this->user->id, 'sender@example.com');
        $this->assertNull(Contact::sole()->name);

        Contact::forgetHandledThisRun();
        Contact::remember($this->user->id, 'sender@example.com', 'Discovered Name');

        $this->assertSame('Discovered Name', Contact::sole()->name);
    }

    public function test_the_memo_does_not_leak_between_users(): void
    {
        $other = User::factory()->create();

        Contact::remember($this->user->id, 'shared@example.com', 'Shared');
        Contact::remember($other->id, 'shared@example.com', 'Shared');

        $this->assertSame(2, Contact::count());
    }

    public function test_junk_addresses_are_still_ignored(): void
    {
        foreach (['', '   ', 'not-an-address'] as $junk) {
            Contact::remember($this->user->id, $junk);
        }

        $this->assertSame(0, Contact::count());
    }
}
