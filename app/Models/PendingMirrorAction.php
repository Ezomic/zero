<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One local action still waiting to reach the mail server. Rows are deleted
 * the moment the server confirms the change, so anything left here is by
 * definition outstanding.
 */
class PendingMirrorAction extends Model
{
    use HasFactory;

    /** Give up after this many attempts and leave the row for inspection. */
    public const MAX_ATTEMPTS = 3;

    protected $fillable = [
        'mail_account_id',
        'email_id',
        'action',
        'remote_folder_path',
        'uid',
        'attempts',
        'last_error',
        'failed_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'failed_at' => 'datetime',
    ];

    /** @return BelongsTo<MailAccount, $this> */
    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    /** @return BelongsTo<Email, $this> */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /** @param Builder<$this> $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('failed_at');
    }

    public function isMove(): bool
    {
        return str_starts_with($this->action, 'move:');
    }

    public function moveTarget(): string
    {
        return substr($this->action, 5);
    }

    /**
     * Retried in place rather than through the queue's own retry: the drain
     * job handles a whole account, so one bad UID must not send every other
     * action in the batch round again.
     */
    public function recordFailure(string $message): void
    {
        $attempts = $this->attempts + 1;

        $this->forceFill([
            'attempts' => $attempts,
            'last_error' => $message,
            'failed_at' => $attempts >= self::MAX_ATTEMPTS ? now() : null,
        ])->save();
    }
}
