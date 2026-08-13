<?php

namespace Tests\Feature\Inbox;

use App\Actions\Mail\BuildScopedEmailQuery;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\SavedSearch;
use App\Models\User;
use App\Services\Mail\SavedSearchCounts;
use App\Support\MailScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SavedSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::factory()->create();
        $this->account = MailAccount::factory()->create(['user_id' => $this->user->id]);
    }

    private function email(array $attributes = []): Email
    {
        return Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'folder' => 'INBOX',
            'uid' => (string) fake()->unique()->numberBetween(1, 999999),
            'is_deleted' => false,
            'is_archived' => false,
            'is_read' => false,
            ...$attributes,
        ]);
    }

    private function save(array $payload = [])
    {
        return $this->actingAs($this->user)
            ->from(route('inbox.index', ['q' => 'invoice']))
            ->post(route('savedSearches.store'), ['name' => 'Invoices', 'query' => 'invoice', ...$payload]);
    }

    public function test_it_saves_the_query_with_its_scope(): void
    {
        $this->save(['account' => $this->account->id, 'folder' => 'SENT'])
            ->assertRedirect(route('inbox.index', ['q' => 'invoice', 'account' => $this->account->id, 'folder' => 'SENT']));

        $this->assertDatabaseHas('saved_searches', [
            'user_id' => $this->user->id,
            'name' => 'Invoices',
            'query' => 'invoice',
            'mail_account_id' => $this->account->id,
            'folder' => 'SENT',
        ]);
    }

    public function test_it_will_not_save_another_users_account_as_the_scope(): void
    {
        $theirs = MailAccount::factory()->create(['user_id' => User::factory()]);

        $this->save(['account' => $theirs->id])->assertRedirect();

        // Dropped rather than stored: a borrowed id would be saved and then
        // filtered out on read, which looks like a broken view.
        $this->assertDatabaseHas('saved_searches', ['name' => 'Invoices', 'mail_account_id' => null]);
    }

    public function test_it_rejects_a_duplicate_name_for_the_same_user(): void
    {
        $this->save();
        $this->save()->assertSessionHasErrors('name');

        $this->assertSame(1, SavedSearch::count());
    }

    public function test_the_link_reproduces_the_result_the_query_gave(): void
    {
        $this->email(['subject' => 'Invoice 2026-01']);
        $this->email(['subject' => 'Lunch plans']);

        $this->save();
        $saved = SavedSearch::firstOrFail();

        $live = $this->actingAs($this->user)->get(route('inbox.index', ['q' => 'invoice']));
        $viaSaved = $this->actingAs($this->user)->get(route('inbox.index', ['q' => $saved->query]));

        $live->assertOk()->assertSee('Invoice 2026-01')->assertDontSee('Lunch plans');
        $viaSaved->assertOk()->assertSee('Invoice 2026-01')->assertDontSee('Lunch plans');
    }

    public function test_the_rail_lists_it_with_its_unread_count(): void
    {
        $this->email(['subject' => 'Invoice one', 'is_read' => false]);
        $this->email(['subject' => 'Invoice two', 'is_read' => false]);
        $this->email(['subject' => 'Invoice three', 'is_read' => true]);

        $this->save();

        $this->actingAs($this->user)->get(route('inbox.index'))
            ->assertOk()
            ->assertSee('Invoices')
            ->assertSeeInOrder(['Invoices', '2']);
    }

    /**
     * The count and the list must not be able to disagree. Both go through
     * BuildScopedEmailQuery, so this pins that they still answer the same
     * question rather than two similar ones.
     */
    public function test_the_count_agrees_with_the_list_it_labels(): void
    {
        foreach (range(1, 4) as $i) {
            $this->email(['subject' => "Invoice {$i}", 'is_read' => $i > 2]);
        }
        $this->email(['subject' => 'Unrelated', 'is_read' => false]);

        $this->save();
        $saved = SavedSearch::firstOrFail();

        $counts = app(SavedSearchCounts::class)
            ->forUser($this->user, $this->user->savedSearches);

        $unreadInScope = app(BuildScopedEmailQuery::class)
            ->handle($this->user, MailScope::fromSavedSearch($saved))
            ->where('is_read', false)
            ->count();

        $this->assertSame(2, $counts[$saved->id]);
        $this->assertSame($unreadInScope, $counts[$saved->id]);
    }

    /**
     * The review note on ZERO-120: a saved view's count is a full FTS query,
     * not an indexed column filter, so the rail must not cost one search per
     * saved view on every page load.
     */
    public function test_rendering_the_rail_does_not_scale_with_the_number_of_saved_views(): void
    {
        $this->email(['subject' => 'Invoice one']);

        $queriesFor = function (int $howMany): int {
            SavedSearch::query()->delete();
            Cache::flush();

            foreach (range(1, $howMany) as $i) {
                SavedSearch::factory()->create([
                    'user_id' => $this->user->id,
                    'name' => "View {$i}",
                    'query' => 'invoice',
                    'position' => $i,
                ]);
            }

            // A freshly loaded user each time: actingAs() binds the instance
            // it is given, and one whose savedSearches relation is already
            // loaded would answer from that copy instead of the new rows.
            $visit = fn () => $this->actingAs(User::findOrFail($this->user->id))
                ->get(route('inbox.index'))
                ->assertOk();

            // Warm the cache first: the point is the steady state, not the
            // first render after an edit.
            $visit();

            DB::enableQueryLog();
            DB::flushQueryLog();
            $visit();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $one = $queriesFor(1);
        $eight = $queriesFor(8);

        $this->assertSame(
            $one,
            $eight,
            "Rendering the rail cost {$one} queries with 1 saved view and {$eight} with 8; it must not grow per view.",
        );
    }

    public function test_a_saved_view_whose_account_was_removed_degrades(): void
    {
        $this->email(['subject' => 'Invoice one']);

        $saved = SavedSearch::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Gone',
            'query' => 'invoice',
            'mail_account_id' => $this->account->id,
        ]);

        $this->account->delete();

        $this->actingAs($this->user)->get(route('inbox.index'))
            ->assertOk()
            ->assertSee('Gone');

        $this->actingAs($this->user)
            ->get(route('inbox.index', ['q' => 'invoice', 'account' => $saved->mail_account_id]))
            ->assertOk()
            ->assertDontSee('Invoice one');
    }

    public function test_a_saved_view_whose_folder_was_removed_degrades(): void
    {
        $this->email(['subject' => 'Invoice one']);

        SavedSearch::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Old folder',
            'query' => 'invoice',
            'folder' => 'Archive/2019',
        ]);

        // The folder no longer exists on any account, so the scope falls back
        // to INBOX rather than filtering on a name nothing carries.
        $this->actingAs($this->user)
            ->get(route('inbox.index', ['q' => 'invoice', 'folder' => 'Archive/2019']))
            ->assertOk()
            ->assertSee('Invoice one');
    }

    public function test_saved_views_are_invisible_to_other_users(): void
    {
        // Created directly rather than through store(), so the "Saved as ..."
        // flash from that request cannot survive into the stranger's page and
        // make this pass or fail for the wrong reason.
        $saved = SavedSearch::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Invoices',
            'query' => 'invoice',
        ]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('inbox.index'))->assertOk()->assertDontSee('Invoices');
        $this->actingAs($stranger)->patch(route('savedSearches.update', $saved), ['name' => 'Theirs'])->assertForbidden();
        $this->actingAs($stranger)->delete(route('savedSearches.destroy', $saved))->assertForbidden();
        $this->actingAs($stranger)->post(route('savedSearches.move', $saved), ['direction' => 'up'])->assertForbidden();

        $this->assertSame('Invoices', $saved->fresh()?->name);
    }

    public function test_it_renames_and_deletes(): void
    {
        $this->save();
        $saved = SavedSearch::firstOrFail();

        $this->actingAs($this->user)->from(route('savedSearches.index'))
            ->patch(route('savedSearches.update', $saved), ['name' => 'Bills'])
            ->assertRedirect(route('savedSearches.index'));

        $this->assertSame('Bills', $saved->fresh()?->name);

        $this->actingAs($this->user)->from(route('savedSearches.index'))
            ->delete(route('savedSearches.destroy', $saved))
            ->assertRedirect();

        $this->assertSame(0, SavedSearch::count());
    }

    public function test_it_reorders_by_swapping_with_the_neighbour(): void
    {
        $first = SavedSearch::factory()->create(['user_id' => $this->user->id, 'name' => 'First', 'position' => 1]);
        $second = SavedSearch::factory()->create(['user_id' => $this->user->id, 'name' => 'Second', 'position' => 2]);
        $third = SavedSearch::factory()->create(['user_id' => $this->user->id, 'name' => 'Third', 'position' => 3]);

        $this->actingAs($this->user)->from(route('savedSearches.index'))
            ->post(route('savedSearches.move', $second), ['direction' => 'up'])
            ->assertRedirect();

        $this->assertSame(2, $first->fresh()?->position);
        $this->assertSame(1, $second->fresh()?->position);
        // The swap leaves everything else where it was.
        $this->assertSame(3, $third->fresh()?->position);
    }

    public function test_moving_the_top_one_up_is_a_no_op(): void
    {
        $first = SavedSearch::factory()->create(['user_id' => $this->user->id, 'name' => 'First', 'position' => 1]);
        $second = SavedSearch::factory()->create(['user_id' => $this->user->id, 'name' => 'Second', 'position' => 2]);

        $this->actingAs($this->user)->from(route('savedSearches.index'))
            ->post(route('savedSearches.move', $first), ['direction' => 'up'])
            ->assertRedirect();

        $this->assertSame(1, $first->fresh()?->position);
        $this->assertSame(2, $second->fresh()?->position);
    }

    public function test_the_manage_page_lists_only_your_own(): void
    {
        $this->save();
        SavedSearch::factory()->create(['user_id' => User::factory(), 'name' => 'Somebody elses']);

        $this->actingAs($this->user)->get(route('savedSearches.index'))
            ->assertOk()
            ->assertSee('Invoices')
            ->assertDontSee('Somebody elses');
    }
}
