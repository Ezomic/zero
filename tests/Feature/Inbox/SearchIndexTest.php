<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The search index is maintained by SQLite triggers, which are invisible to
 * the rest of the app: nothing throws when they go missing, and a stale index
 * answers a MATCH happily with whatever it still holds. A recreation of the
 * emails table silently destroyed them once already (ZERO-98), so these
 * assert the index is live rather than merely present.
 */
class SearchIndexTest extends TestCase
{
    use RefreshDatabase;

    private function email(array $attributes = []): Email
    {
        $account = MailAccount::factory()->create(['user_id' => User::factory()]);

        return Email::factory()->create([
            'mail_account_id' => $account->id,
            'folder' => 'INBOX',
            ...$attributes,
        ]);
    }

    /** @return list<string> */
    private function triggers(): array
    {
        return array_map(
            fn (object $row): string => (string) $row->name,
            DB::select("select name from sqlite_master where type = 'trigger' and name like 'emails_fts%'"),
        );
    }

    private function hitsFor(string $expression): int
    {
        return DB::table('emails_fts')->whereRaw('emails_fts MATCH ?', [$expression])->count();
    }

    public function test_all_three_index_triggers_survive_the_migrations(): void
    {
        $this->assertEqualsCanonicalizing(
            ['emails_fts_ai', 'emails_fts_ad', 'emails_fts_au'],
            $this->triggers(),
        );
    }

    public function test_a_new_email_is_immediately_findable(): void
    {
        $this->email(['subject' => 'Zeppelin maintenance schedule']);

        $this->assertSame(1, $this->hitsFor('"zeppelin"*'));
    }

    public function test_an_edited_subject_is_reindexed(): void
    {
        $email = $this->email(['subject' => 'Zeppelin maintenance schedule']);

        $email->update(['subject' => 'Dirigible maintenance schedule']);

        $this->assertSame(0, $this->hitsFor('"zeppelin"*'));
        $this->assertSame(1, $this->hitsFor('"dirigible"*'));
    }

    public function test_a_deleted_email_leaves_the_index(): void
    {
        $email = $this->email(['subject' => 'Zeppelin maintenance schedule']);

        $email->delete();

        $this->assertSame(0, $this->hitsFor('"zeppelin"*'));
    }

    public function test_the_body_and_sender_are_indexed_too(): void
    {
        $this->email([
            'subject' => 'Nothing useful here',
            'from_address' => 'captain@zeppelin.example',
            'body_text' => 'The mooring mast needs inspecting.',
        ]);

        $this->assertSame(1, $this->hitsFor('"zeppelin"*'));
        $this->assertSame(1, $this->hitsFor('"mooring"*'));
    }
}
