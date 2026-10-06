<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Archives new INBOX mail from a sender address or domain on arrival.
 *
 * Archive is a local flag, so a rule never touches the mail server and
 * every message it files can be put back (ZERO-127).
 *
 * @property int $id
 * @property int $user_id
 * @property ?int $mail_account_id
 * @property string $kind
 * @property string $value
 */
class ArchiveRule extends Model
{
    public const ADDRESS = 'address';

    public const DOMAIN = 'domain';

    protected $fillable = [
        'user_id',
        'mail_account_id',
        'kind',
        'value',
    ];

    /**
     * Rules that apply to the account being synced, held for the run for the
     * same reason as MutedThread: storeMessage() asks once per message.
     *
     * @var array<int, array<int, array{id: int, kind: string, value: string}>>
     */
    protected static array $memoByAccount = [];

    protected static function booted(): void
    {
        $forget = static fn () => static::forgetMemo();

        static::saved($forget);
        static::deleted($forget);
    }

    public static function forgetMemo(): void
    {
        static::$memoByAccount = [];
    }

    public static function matchFor(MailAccount $account, ?string $fromAddress): ?int
    {
        $from = strtolower(trim((string) $fromAddress));

        if ($from === '' || ! str_contains($from, '@')) {
            return null;
        }

        $domain = substr($from, (int) strrpos($from, '@') + 1);

        foreach (static::rulesFor($account) as $rule) {
            if ($rule['kind'] === self::ADDRESS && $rule['value'] === $from) {
                return $rule['id'];
            }

            if ($rule['kind'] === self::DOMAIN && ($domain === $rule['value'] || str_ends_with($domain, '.'.$rule['value']))) {
                return $rule['id'];
            }
        }

        return null;
    }

    /** @return array<int, array{id: int, kind: string, value: string}> */
    protected static function rulesFor(MailAccount $account): array
    {
        $accountId = (int) $account->id;

        return static::$memoByAccount[$accountId] ??= static::query()
            ->where('user_id', $account->user_id)
            ->where(fn ($query) => $query->whereNull('mail_account_id')->orWhere('mail_account_id', $accountId))
            ->get(['id', 'kind', 'value'])
            ->map(fn (self $rule): array => ['id' => $rule->id, 'kind' => $rule->kind, 'value' => $rule->value])
            ->all();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
