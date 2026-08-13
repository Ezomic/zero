<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A conversation whose later replies should skip the inbox.
 *
 * Archiving only settles a thread until someone replies. For one that has
 * stopped being relevant but has not stopped moving, the choice used to be
 * archiving it over and over or deleting mail that is still wanted
 * (ZERO-119).
 */
class MutedThread extends Model
{
    protected $fillable = [
        'mail_account_id',
        'thread_id',
    ];

    /**
     * Muted thread ids for the account being synced.
     *
     * Held for the run because storeMessage() consults it once per message,
     * and a query per message in the sync hot path is the amplification
     * ZERO-107 went to some trouble to remove.
     *
     * @var array<int, array<string, true>>
     */
    protected static array $memoByAccount = [];

    public static function forgetMemo(): void
    {
        static::$memoByAccount = [];
    }

    public static function isMuted(int $accountId, ?string $threadId): bool
    {
        if ($threadId === null || $threadId === '') {
            return false;
        }

        if (! isset(static::$memoByAccount[$accountId])) {
            $muted = [];

            foreach (static::query()->where('mail_account_id', $accountId)->get(['thread_id']) as $row) {
                $muted[$row->thread_id] = true;
            }

            static::$memoByAccount[$accountId] = $muted;
        }

        return isset(static::$memoByAccount[$accountId][$threadId]);
    }

    /** @return BelongsTo<MailAccount, $this> */
    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }
}
