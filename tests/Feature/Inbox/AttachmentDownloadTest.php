<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function attachmentFor(User $user, bool $writeFile = true): EmailAttachment
    {
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $email = Email::factory()->create(['mail_account_id' => $account->id]);

        $path = "email-attachments/{$account->id}/{$email->id}/quarterly report.pdf";

        if ($writeFile) {
            Storage::disk('local')->put($path, '%PDF-1.4 fake');
        }

        return EmailAttachment::create([
            'email_id' => $email->id,
            'filename' => 'quarterly report.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 13,
            'storage_path' => $path,
        ]);
    }

    public function test_the_owner_can_download_an_attachment(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $attachment = $this->attachmentFor($user);

        $response = $this->actingAs($user)->get(route('attachments.download', $attachment));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertSame('%PDF-1.4 fake', $response->streamedContent());
    }

    public function test_the_download_carries_the_original_filename(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $attachment = $this->attachmentFor($user);

        $disposition = $this->actingAs($user)
            ->get(route('attachments.download', $attachment))
            ->headers->get('content-disposition');

        $this->assertStringContainsString('attachment;', (string) $disposition);
        $this->assertStringContainsString('quarterly report.pdf', rawurldecode((string) $disposition));
    }

    /**
     * An attachment is arbitrary content from an arbitrary sender, so it must
     * never render on this app's own origin.
     */
    public function test_an_attachment_is_never_served_inline(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $email = Email::factory()->create(['mail_account_id' => $account->id]);
        Storage::disk('local')->put('email-attachments/x.html', '<script>alert(1)</script>');

        $attachment = EmailAttachment::create([
            'email_id' => $email->id,
            'filename' => 'payload.html',
            'mime_type' => 'text/html',
            'storage_path' => 'email-attachments/x.html',
        ]);

        $disposition = $this->actingAs($user)
            ->get(route('attachments.download', $attachment))
            ->headers->get('content-disposition');

        $this->assertStringStartsWith('attachment;', (string) $disposition);
    }

    public function test_another_user_cannot_download_the_attachment(): void
    {
        Storage::fake('local');

        $attachment = $this->attachmentFor(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->get(route('attachments.download', $attachment))
            ->assertForbidden();
    }

    public function test_a_guest_cannot_download_the_attachment(): void
    {
        Storage::fake('local');

        $attachment = $this->attachmentFor(User::factory()->create());

        $this->get(route('attachments.download', $attachment))->assertRedirect();
    }

    public function test_a_row_whose_file_is_gone_returns_404_rather_than_an_empty_stream(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $attachment = $this->attachmentFor($user, writeFile: false);

        $this->actingAs($user)
            ->get(route('attachments.download', $attachment))
            ->assertNotFound();
    }

    public function test_the_reading_pane_links_every_attachment(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $attachment = $this->attachmentFor($user);

        $this->actingAs($user)
            ->get(route('inbox.panel', $attachment->email))
            ->assertOk()
            ->assertSee(route('attachments.download', $attachment), false);
    }
}
