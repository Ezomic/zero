<?php

namespace App\Support;

use App\Models\Email;
use App\Models\MutedThread;

/**
 * Which of an account's conversations are currently snoozed.
 *
 * A reply to a snoozed conversation brings it back early, which means
 * storeMessage() has to ask the question once per stored message. Asking the
 * database each time is the per-message amplification ZERO-107 went to some
 * trouble to remove, so the answer is held for the run in the same shape
 * MutedThread uses. Snoozed threads are few by nature, so the one query that
 * fills this is cheap regardless of mailbox size (ZERO-114).
 *
 * @see MutedThread
 */
final class SnoozedThreads
{
    /** @var array<int, array<string, true>> */
    private static array $memoByAccount = [];

    public static function forgetMemo(): void
    {
        self::$memoByAccount = [];
    }

    public static function isSnoozed(int $accountId, ?string $threadId): bool
    {
        if ($threadId === null || $threadId === '') {
            return false;
        }

        if (! isset(self::$memoByAccount[$accountId])) {
            $snoozed = [];

            $rows = Email::query()
                ->where('mail_account_id', $accountId)
                ->snoozed()
                ->distinct()
                ->pluck('thread_id');

            foreach ($rows as $threadIdRow) {
                if (is_string($threadIdRow) && $threadIdRow !== '') {
                    $snoozed[$threadIdRow] = true;
                }
            }

            self::$memoByAccount[$accountId] = $snoozed;
        }

        return isset(self::$memoByAccount[$accountId][$threadId]);
    }

    /**
     * A thread woken during the run must not be woken again for every later
     * message in the same batch.
     */
    public static function forget(int $accountId, string $threadId): void
    {
        unset(self::$memoByAccount[$accountId][$threadId]);
    }
}
