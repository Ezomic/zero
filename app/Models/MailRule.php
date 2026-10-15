<?php

namespace App\Models;

use App\Support\MailRules\MessageFacts;
use App\Support\MailRules\RuleMatcher;
use App\Support\MailRules\RuleOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's standing instruction for mail as it arrives: when this matches, do
 * that, without asking (ZERO-118).
 *
 * Rules apply in `position` order and every matching rule applies; `file`
 * is the one action that can only happen once, so the first rule to name a
 * folder decides it. A rule marked `stop_on_match` ends the run. No action
 * deletes anything.
 *
 * @property int $id
 * @property int $user_id
 * @property ?int $mail_account_id
 * @property int $position
 * @property string $field
 * @property string $operator
 * @property string $value
 * @property string $action
 * @property ?string $folder
 * @property bool $stop_on_match
 */
class MailRule extends Model
{
    public const FROM = 'from';

    public const TO = 'to';

    public const SUBJECT = 'subject';

    public const FIELDS = [self::FROM, self::TO, self::SUBJECT];

    public const CONTAINS = 'contains';

    public const IS = 'is';

    public const DOMAIN = 'domain';

    public const OPERATORS = [self::CONTAINS, self::IS, self::DOMAIN];

    public const ARCHIVE = 'archive';

    public const STAR = 'star';

    public const MARK_READ = 'mark_read';

    public const FILE = 'file';

    public const ACTIONS = [self::ARCHIVE, self::STAR, self::MARK_READ, self::FILE];

    public const MAX_PATTERN = 255;

    /**
     * Folders a rule may never file into. Trash because filing there is
     * deleting by another name; the rest because they are not places incoming
     * mail belongs.
     */
    public const UNFILEABLE = ['INBOX', 'SENT', 'DRAFTS', 'TRASH'];

    protected $fillable = [
        'user_id',
        'mail_account_id',
        'position',
        'field',
        'operator',
        'value',
        'action',
        'folder',
        'stop_on_match',
    ];

    protected $casts = [
        'position' => 'integer',
        'stop_on_match' => 'boolean',
    ];

    /**
     * The usable rules for the account being synced, held for the run because
     * storeMessage() asks once per message, and a query per message in the
     * sync hot path is the amplification ZERO-107 removed.
     *
     * It is also reloaded once it is a minute old. A sync can run for half an
     * hour in a different process from the page that edits rules, and a rule
     * deleted meanwhile must stop acting without waiting for the run to end.
     *
     * @var array<int, list<self>>
     */
    protected static array $memoByAccount = [];

    /** @var array<int, int> */
    protected static array $memoLoadedAt = [];

    protected const MEMO_SECONDS = 60;

    protected static function booted(): void
    {
        $forget = static fn () => static::forgetMemo();

        static::saved($forget);
        static::deleted($forget);
    }

    public static function forgetMemo(): void
    {
        static::$memoByAccount = [];
        static::$memoLoadedAt = [];
    }

    public static function outcomeFor(MailAccount $account, MessageFacts $facts): RuleOutcome
    {
        return RuleMatcher::evaluate(static::rulesFor($account), $facts);
    }

    /**
     * In the order they apply. Rules that cannot act are left out here, once,
     * rather than per message: an unknown action, or a file rule whose folder
     * is not one of this account's (deleted, renamed, or never theirs).
     *
     * @return list<self>
     */
    public static function rulesFor(MailAccount $account): array
    {
        $accountId = (int) $account->id;

        if (isset(static::$memoByAccount[$accountId]) && now()->getTimestamp() - (static::$memoLoadedAt[$accountId] ?? 0) < self::MEMO_SECONDS) {
            return static::$memoByAccount[$accountId];
        }

        $rules = static::query()
            ->where('user_id', $account->user_id)
            ->where(fn ($query) => $query->whereNull('mail_account_id')->orWhere('mail_account_id', $accountId))
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(fn (self $rule): bool => in_array($rule->action, self::ACTIONS, true))
            ->values();

        $folders = $rules->contains(fn (self $rule): bool => $rule->action === self::FILE)
            ? MailFolder::query()->where('mail_account_id', $accountId)->get(['local_name'])->map(fn (MailFolder $folder): string => $folder->local_name)->all()
            : [];

        static::$memoLoadedAt[$accountId] = now()->getTimestamp();

        return static::$memoByAccount[$accountId] = array_values(
            $rules->filter(fn (self $rule): bool => $rule->action !== self::FILE || static::canFileInto($rule->folder, $folders))->all()
        );
    }

    /** @param array<int, string> $accountFolders */
    protected static function canFileInto(?string $folder, array $accountFolders): bool
    {
        return $folder !== null
            && ! in_array(strtoupper($folder), self::UNFILEABLE, true)
            && in_array($folder, $accountFolders, true);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
