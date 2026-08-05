<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = MailAccount::factory()->create(['user_id' => $this->user->id]);
    }

    private function email(array $attributes = []): Email
    {
        return Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'folder' => 'INBOX',
            'is_read' => true,
            'is_archived' => false,
            'is_deleted' => false,
            ...$attributes,
        ]);
    }

    public function test_search_matches_on_subject(): void
    {
        $this->email(['subject' => 'Quarterly invoice attached']);
        $this->email(['subject' => 'Lunch on Friday']);

        $this->actingAs($this->user)
            ->get(route('inbox.index', ['q' => 'invoice']))
            ->assertOk()
            ->assertSee('Quarterly invoice attached')
            ->assertDontSee('Lunch on Friday');
    }

    public function test_search_matches_on_sender(): void
    {
        $this->email(['subject' => 'From the bank', 'from_address' => 'noreply@bunq.com']);
        $this->email(['subject' => 'From a friend', 'from_address' => 'sam@example.com']);

        $this->actingAs($this->user)
            ->get(route('inbox.index', ['q' => 'bunq']))
            ->assertOk()
            ->assertSee('From the bank')
            ->assertDontSee('From a friend');
    }

    public function test_multiple_terms_are_all_required(): void
    {
        $this->email(['subject' => 'Quarterly invoice attached']);
        $this->email(['subject' => 'Quarterly report attached']);

        $this->actingAs($this->user)
            ->get(route('inbox.index', ['q' => 'quarterly invoice']))
            ->assertOk()
            ->assertSee('Quarterly invoice attached')
            ->assertDontSee('Quarterly report attached');
    }

    public function test_another_users_mail_never_matches(): void
    {
        $other = MailAccount::factory()->create(['user_id' => User::factory()]);
        Email::factory()->create([
            'mail_account_id' => $other->id,
            'folder' => 'INBOX',
            'subject' => 'Somebody elses invoice',
        ]);
        $this->email(['subject' => 'My own invoice']);

        $this->actingAs($this->user)
            ->get(route('inbox.index', ['q' => 'invoice']))
            ->assertOk()
            ->assertSee('My own invoice')
            ->assertDontSee('Somebody elses invoice');
    }

    /**
     * The regression this ticket is about. Resolving matches into a whereIn
     * bound one parameter per hit, and SQLite rejects a statement carrying
     * more than ~32k of them, so a common term could only ever fail.
     */
    public function test_a_term_matching_more_rows_than_sqlites_bind_limit_still_searches(): void
    {
        $bindLimit = 32766;
        $total = $bindLimit + 500;
        $sentAt = now()->toDateTimeString();

        // Built and inserted a chunk at a time: holding all 33k rows in PHP
        // at once exhausts the test process, and one insert carrying them all
        // would hit the very bind limit this test is about.
        for ($offset = 0; $offset < $total; $offset += 500) {
            $chunk = [];

            for ($i = $offset; $i < min($offset + 500, $total); $i++) {
                $chunk[] = [
                    'mail_account_id' => $this->account->id,
                    'ulid' => (string) Str::ulid(),
                    'thread_id' => "bulk:{$i}",
                    'folder' => 'INBOX',
                    'uid' => (string) (100000 + $i),
                    'subject' => 'Newsletter edition '.$i,
                    'from_address' => 'news@example.com',
                    'is_read' => true,
                    'is_archived' => false,
                    'is_deleted' => false,
                    'sent_at' => $sentAt,
                ];
            }

            DB::table('emails')->insert($chunk);
        }

        $this->assertGreaterThan(
            $bindLimit,
            DB::table('emails_fts')->whereRaw('emails_fts MATCH ?', ['"newsletter"*'])->count(),
            'the fixture must match more rows than SQLite will bind',
        );

        $this->actingAs($this->user)
            ->get(route('inbox.index', ['q' => 'newsletter']))
            ->assertOk()
            ->assertSee('Newsletter edition');
    }

    public function test_search_falls_back_to_like_when_the_fts_table_is_missing(): void
    {
        $this->email(['subject' => 'Quarterly invoice attached']);
        $this->email(['subject' => 'Lunch on Friday']);

        DB::statement('DROP TABLE emails_fts');

        $this->actingAs($this->user)
            ->get(route('inbox.index', ['q' => 'invoice']))
            ->assertOk()
            ->assertSee('Quarterly invoice attached')
            ->assertDontSee('Lunch on Friday');
    }
}
