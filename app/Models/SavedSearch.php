<?php

namespace App\Models;

use Database\Factories\SavedSearchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A search worth keeping: the query plus the scope it was run under.
 *
 * Search became genuinely useful once ZERO-98 restored the index and ZERO-102
 * put message bodies in it. What it lacked was memory, so the recurring
 * queries had to be retyped every time (ZERO-120).
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $query
 * @property ?int $mail_account_id
 * @property ?string $folder
 * @property bool $archived
 * @property bool $starred
 * @property int $position
 */
class SavedSearch extends Model
{
    /** @use HasFactory<SavedSearchFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'query',
        'mail_account_id',
        'folder',
        'archived',
        'starred',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'archived' => 'boolean',
            'starred' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The account this view was saved against, or null when it was saved
     * across all of them. A deleted account resolves to null here while the
     * column keeps its id, which is what lets the UI say the account is gone
     * instead of pretending the view still works.
     */
    public function account(User $user): ?MailAccount
    {
        if ($this->mail_account_id === null) {
            return null;
        }

        return $user->mailAccounts->firstWhere('id', $this->mail_account_id);
    }

    public function accountIsMissing(User $user): bool
    {
        return $this->mail_account_id !== null && $this->account($user) === null;
    }
}
