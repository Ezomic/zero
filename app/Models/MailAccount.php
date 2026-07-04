<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $email_address
 * @property string|null $display_name
 * @property string $provider
 * @property string $sync_status
 * @property bool $is_active
 */
class MailAccount extends Model
{
    use HasFactory;

    public const PROVIDER_GMAIL = 'gmail';

    public const PROVIDER_OUTLOOK = 'outlook';

    public const PROVIDER_IMAP = 'imap';

    /** Palette used to auto-assign a distinguishing color per account. */
    public const COLOR_PALETTE = [
        '#3B82F6', '#10B981', '#F59E0B', '#EF4444',
        '#8B5CF6', '#EC4899', '#14B8A6', '#F97316',
    ];

    protected $fillable = [
        'user_id',
        'email_address',
        'display_name',
        'color',
        'provider',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'imap_username',
        'imap_password',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'oauth_access_token',
        'oauth_refresh_token',
        'oauth_expires_at',
        'last_synced_at',
        'sync_status',
        'sync_status_since',
        'sync_error',
        'is_active',
    ];

    // Sensitive fields are encrypted at rest automatically via Laravel's
    // 'encrypted' cast, which uses your app's APP_KEY.
    protected $casts = [
        'imap_password' => 'encrypted',
        'smtp_password' => 'encrypted',
        'oauth_access_token' => 'encrypted',
        'oauth_refresh_token' => 'encrypted',
        'oauth_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'sync_status_since' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'imap_password',
        'smtp_password',
        'oauth_access_token',
        'oauth_refresh_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    public function folders(): HasMany
    {
        return $this->hasMany(MailFolder::class);
    }

    public function usesOAuth(): bool
    {
        return in_array($this->provider, [self::PROVIDER_GMAIL, self::PROVIDER_OUTLOOK], true);
    }

    public function tokenIsExpired(): bool
    {
        return $this->oauth_expires_at !== null && $this->oauth_expires_at->isPast();
    }

    protected static function booted(): void
    {
        static::creating(function (self $account) {
            if (! $account->color) {
                $account->color = self::COLOR_PALETTE[array_rand(self::COLOR_PALETTE)];
            }
        });
    }

    public function unreadCount(): int
    {
        return $this->emails()
            ->where('folder', 'INBOX')
            ->where('is_read', false)
            ->where('is_archived', false)
            ->where('is_deleted', false)
            ->count();
    }
}
