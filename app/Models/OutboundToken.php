<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A credential for another app to send mail through zero (ZERO-131). Send-only
 * by construction: the only thing a token can do is reach POST /api/outbound,
 * and that sends from its one account.
 *
 * @property int $id
 * @property string $name
 * @property string $token_hash
 * @property int $mail_account_id
 * @property ?CarbonImmutable $revoked_at
 */
#[Fillable(['name', 'token_hash', 'mail_account_id', 'revoked_at'])]
#[Hidden(['token_hash'])]
class OutboundToken extends Model
{
    private const PREFIX = 'zob_';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revoked_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<MailAccount, $this> */
    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    /**
     * @return array{0: self, 1: string} The row and the plain token, which exists nowhere else.
     */
    public static function issue(string $name, MailAccount $account): array
    {
        $plain = self::generate();

        $token = self::create([
            'name' => $name,
            'token_hash' => self::hash($plain),
            'mail_account_id' => $account->id,
        ]);

        return [$token, $plain];
    }

    /** Replaces the secret in place and returns the new plain token. */
    public function rotate(): string
    {
        $plain = self::generate();

        $this->forceFill(['token_hash' => self::hash($plain)])->save();

        return $plain;
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    protected static function generate(): string
    {
        return self::PREFIX.bin2hex(random_bytes(32));
    }
}
