<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MailFolder extends Model
{
    /** Canonical folders that always get a friendly, fixed label. */
    protected const CANONICAL = ['INBOX', 'SENT', 'DRAFTS', 'TRASH'];

    protected $fillable = [
        'mail_account_id',
        'local_name',
        'remote_path',
        'last_uid',
        'uid_validity',
    ];

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    /**
     * Custom folders are stored by full path (to disambiguate labels that
     * share a leaf name at different nesting levels) — show just the last
     * segment so tabs/dropdowns stay readable.
     */
    public static function displayName(string $localName): string
    {
        if (in_array($localName, self::CANONICAL, true)) {
            return ucfirst(strtolower($localName));
        }

        return Str::afterLast($localName, '/');
    }
}
