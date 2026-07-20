<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class Email extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        // A ULID is a durable, folder-independent identity for the message,
        // used by other apps to deep-link back to it. The sync service reuses
        // an existing row's ULID when the same message reappears in another
        // folder, so only generate one when none was supplied.
        static::creating(function (Email $email): void {
            $email->ulid ??= (string) Str::ulid();
        });
    }

    protected $fillable = [
        'mail_account_id',
        'ulid',
        'message_id',
        'thread_id',
        'in_reply_to',
        'references_header',
        'folder',
        'remote_folder_path',
        'uid',
        'subject',
        'from_address',
        'from_name',
        'to_addresses',
        'cc_addresses',
        'body_html',
        'body_text',
        'is_read',
        'is_archived',
        'is_deleted',
        'has_attachments',
        'sent_at',
    ];

    protected $casts = [
        'to_addresses' => 'array',
        'cc_addresses' => 'array',
        'is_read' => 'boolean',
        'is_archived' => 'boolean',
        'is_deleted' => 'boolean',
        'has_attachments' => 'boolean',
        'sent_at' => 'datetime',
    ];

    /** @return BelongsTo<MailAccount, $this> */
    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    /**
     * The owning account, for the sync paths that cannot proceed without one.
     * emails.mail_account_id is NOT NULL behind a cascading foreign key, so a
     * null here means the row was orphaned outside Eloquent.
     */
    public function requireMailAccount(): MailAccount
    {
        $account = $this->mailAccount;

        if ($account === null) {
            throw new RuntimeException("Email {$this->id} has no mail account.");
        }

        return $account;
    }

    /** @return HasMany<EmailAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }

    /**
     * All messages in the same conversation (same account + thread_id), oldest first.
     *
     * @return Builder<self>
     */
    public function threadMessages(): Builder
    {
        return self::query()
            ->where('mail_account_id', $this->mail_account_id)
            ->where('thread_id', $this->thread_id)
            ->where('is_deleted', false)
            ->with('attachments', 'mailAccount')
            ->oldest('sent_at');
    }

    /**
     * Best-guess destination folder for this message, based on where other
     * mail from the same sender (or failing that, the same sender domain)
     * has already ended up — reflects the mailbox's existing organization
     * (whether from prior manual moves in this app or filters already
     * applied server-side) rather than a hardcoded rule set.
     */
    public function suggestedFolder(): ?string
    {
        if (! $this->from_address) {
            return null;
        }

        $excluded = ['INBOX', 'SENT', 'DRAFTS', 'TRASH'];

        $bySender = static::query()
            ->where('mail_account_id', $this->mail_account_id)
            ->where('from_address', $this->from_address)
            ->where('is_deleted', false)
            ->whereNotIn('folder', $excluded)
            ->select('folder', DB::raw('count(*) as cnt'))
            ->groupBy('folder')
            ->orderByDesc('cnt')
            ->value('folder');

        if ($bySender) {
            return $bySender;
        }

        $domain = Str::after($this->from_address, '@');

        if ($domain === $this->from_address || $domain === '') {
            return null;
        }

        return static::query()
            ->where('mail_account_id', $this->mail_account_id)
            ->where('from_address', 'like', '%@'.$domain)
            ->where('is_deleted', false)
            ->whereNotIn('folder', $excluded)
            ->select('folder', DB::raw('count(*) as cnt'))
            ->groupBy('folder')
            ->orderByDesc('cnt')
            ->value('folder');
    }
}
