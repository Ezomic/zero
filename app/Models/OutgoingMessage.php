<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message that has been sent from the composer but may not have left yet.
 *
 * held -> sending -> sent | failed, or held -> cancelled. Every move out of
 * `held` is a conditional UPDATE on the row, so the undo request and the
 * queued job can never both win (ZERO-117).
 *
 * @property int $id
 * @property int $user_id
 * @property ?int $mail_account_id
 * @property string $status
 * @property CarbonImmutable $send_at
 * @property list<string> $to_addresses
 * @property list<string> $cc_addresses
 * @property string $subject
 * @property string $body
 * @property ?string $in_reply_to
 * @property ?string $references_header
 * @property list<array{path: string, name: string, mime: string, size: int}> $attachments
 * @property ?int $draft_id
 * @property ?string $error
 * @property ?CarbonImmutable $dispatched_at
 * @property ?CarbonImmutable $sending_at
 * @property ?CarbonImmutable $sent_at
 * @property ?CarbonImmutable $failed_at
 * @property ?CarbonImmutable $cancelled_at
 * @property ?CarbonImmutable $acknowledged_at
 */
#[Fillable([
    'user_id',
    'mail_account_id',
    'status',
    'send_at',
    'to_addresses',
    'cc_addresses',
    'subject',
    'body',
    'in_reply_to',
    'references_header',
    'attachments',
    'draft_id',
    'dispatched_at',
])]
class OutgoingMessage extends Model
{
    public const HELD = 'held';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'send_at' => 'immutable_datetime',
            'to_addresses' => 'array',
            'cc_addresses' => 'array',
            'attachments' => 'array',
            'dispatched_at' => 'immutable_datetime',
            'sending_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<MailAccount, $this> */
    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    public function attachmentDirectory(): string
    {
        return (string) $this->id;
    }

    public function canBeReopened(): bool
    {
        return in_array($this->status, [self::CANCELLED, self::FAILED], true);
    }
}
