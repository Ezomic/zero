<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\MailAccount;
use App\Models\User;
use App\Support\AttachmentKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentBrowserTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->account = MailAccount::factory()->create(['user_id' => $this->user->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $emailAttributes
     */
    private function attachment(array $attributes = [], array $emailAttributes = [], bool $onDisk = true, ?MailAccount $account = null): EmailAttachment
    {
        $email = Email::factory()->create([
            'mail_account_id' => ($account ?? $this->account)->id,
            'folder' => 'INBOX',
            'uid' => (string) fake()->unique()->numberBetween(1, 999999),
            'is_deleted' => false,
            ...$emailAttributes,
        ]);

        $path = 'email-attachments/'.fake()->unique()->uuid().'.bin';

        if ($onDisk) {
            Storage::disk('local')->put($path, 'contents');
        }

        return EmailAttachment::create([
            'email_id' => $email->id,
            'filename' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'storage_path' => $path,
            ...$attributes,
        ]);
    }

    private function browse(array $query = [])
    {
        return $this->actingAs($this->user)->get(route('attachments.index', $query));
    }

    public function test_it_lists_the_users_attachments(): void
    {
        $this->attachment(['filename' => 'invoice-2026.pdf']);
        $this->attachment(['filename' => 'photo.jpg', 'mime_type' => 'image/jpeg']);

        $this->browse()
            ->assertOk()
            ->assertSee('invoice-2026.pdf')
            ->assertSee('photo.jpg');
    }

    public function test_it_does_not_list_another_users_attachments(): void
    {
        $theirAccount = MailAccount::factory()->create(['user_id' => User::factory()]);
        $this->attachment(['filename' => 'not-yours.pdf'], account: $theirAccount);
        $this->attachment(['filename' => 'mine.pdf']);

        $this->browse()
            ->assertOk()
            ->assertSee('mine.pdf')
            ->assertDontSee('not-yours.pdf');
    }

    public function test_a_deleted_message_takes_its_attachments_out_of_the_list(): void
    {
        $this->attachment(['filename' => 'binned.pdf'], ['is_deleted' => true]);
        $this->attachment(['filename' => 'kept.pdf']);

        $this->browse()->assertOk()->assertSee('kept.pdf')->assertDontSee('binned.pdf');
    }

    public function test_it_searches_by_filename(): void
    {
        $this->attachment(['filename' => 'q3-invoice.pdf']);
        $this->attachment(['filename' => 'holiday-photo.jpg', 'mime_type' => 'image/jpeg']);

        $this->browse(['q' => 'invoice'])
            ->assertOk()
            ->assertSee('q3-invoice.pdf')
            ->assertDontSee('holiday-photo.jpg');
    }

    /**
     * ZERO-91 removed an unbounded IN(...) from search. The scoping here goes
     * through a subquery for the same reason, so no query may carry a
     * parameter per row.
     */
    public function test_scoping_does_not_bind_a_parameter_per_row(): void
    {
        foreach (range(1, 40) as $i) {
            $this->attachment(['filename' => "file-{$i}.pdf"]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->browse()->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $widest = max(array_map(fn ($entry) => count($entry['bindings']), $log));

        $this->assertLessThan(
            20,
            $widest,
            "A query bound {$widest} parameters; the scope must not grow one binding per attachment.",
        );
    }

    public function test_it_filters_by_kind(): void
    {
        $this->attachment(['filename' => 'contract.pdf', 'mime_type' => 'application/pdf']);
        $this->attachment(['filename' => 'snap.png', 'mime_type' => 'image/png']);
        $this->attachment(['filename' => 'backup.zip', 'mime_type' => 'application/zip']);

        $this->browse(['type' => AttachmentKind::IMAGES])
            ->assertOk()->assertSee('snap.png')->assertDontSee('contract.pdf')->assertDontSee('backup.zip');

        $this->browse(['type' => AttachmentKind::DOCUMENTS])
            ->assertOk()->assertSee('contract.pdf')->assertDontSee('snap.png');

        $this->browse(['type' => AttachmentKind::ARCHIVES])
            ->assertOk()->assertSee('backup.zip')->assertDontSee('contract.pdf');
    }

    /**
     * Plenty of senders attach an ordinary PDF as application/octet-stream, so
     * the filter has to look at the extension too or it hides exactly what
     * someone came looking for.
     */
    public function test_kind_filtering_falls_back_to_the_extension(): void
    {
        $this->attachment(['filename' => 'mislabelled.pdf', 'mime_type' => 'application/octet-stream']);

        $this->browse(['type' => AttachmentKind::DOCUMENTS])->assertOk()->assertSee('mislabelled.pdf');
    }

    public function test_an_unknown_kind_is_ignored_rather_than_filtering_everything_out(): void
    {
        $this->attachment(['filename' => 'contract.pdf']);

        $this->browse(['type' => 'nonsense'])->assertOk()->assertSee('contract.pdf');
    }

    public function test_it_filters_by_account(): void
    {
        $other = MailAccount::factory()->create(['user_id' => $this->user->id]);
        $this->attachment(['filename' => 'first-account.pdf']);
        $this->attachment(['filename' => 'second-account.pdf'], account: $other);

        $this->browse(['account' => $other->id])
            ->assertOk()
            ->assertSee('second-account.pdf')
            ->assertDontSee('first-account.pdf');
    }

    public function test_it_paginates_rather_than_loading_everything(): void
    {
        foreach (range(1, 35) as $i) {
            $this->attachment(['filename' => "file-{$i}.pdf"]);
        }

        $response = $this->browse()->assertOk();

        $this->assertSame(30, $response->viewData('attachments')->count());
        $this->assertSame(35, $response->viewData('attachments')->total());
    }

    public function test_a_row_whose_file_is_gone_is_shown_as_unavailable(): void
    {
        $missing = $this->attachment(['filename' => 'vanished.pdf'], onDisk: false);
        $present = $this->attachment(['filename' => 'still-here.pdf']);

        $this->browse()
            ->assertOk()
            ->assertSee('vanished.pdf')
            ->assertSee('Unavailable')
            // No link offered for the missing one, so it cannot 404 on click.
            ->assertDontSee(route('attachments.download', $missing))
            ->assertSee(route('attachments.download', $present));
    }

    public function test_each_row_links_to_the_message_that_carried_it(): void
    {
        $attachment = $this->attachment([], ['subject' => 'Here is the contract']);

        $this->browse()
            ->assertOk()
            ->assertSee('Here is the contract')
            ->assertSee(route('inbox.show', $attachment->email_id));
    }

    public function test_the_download_stays_owner_scoped(): void
    {
        $theirAccount = MailAccount::factory()->create(['user_id' => User::factory()]);
        $theirs = $this->attachment(account: $theirAccount);

        $this->actingAs($this->user)->get(route('attachments.download', $theirs))->assertForbidden();
    }

    public function test_the_browser_needs_a_login(): void
    {
        $this->get(route('attachments.index'))->assertRedirect(route('login'));
    }
}
