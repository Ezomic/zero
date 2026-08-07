<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'name',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * How stale last_seen_at may get before a sighting is worth writing.
     *
     * It orders the autocomplete, so it needs to be roughly right rather than
     * exact. Refreshing it on every sighting meant a write per address per
     * message: about 20,000 statements to maintain 479 rows on production,
     * nearly all of them rewriting a timestamp that had moved by seconds
     * (ZERO-107).
     */
    protected const FRESH_FOR_HOURS = 24;

    /**
     * Addresses already dealt with in the current sync run.
     *
     * A mailbox sees the same handful of senders thousands of times, and
     * without this each sighting still costs a SELECT even when it writes
     * nothing. Reset per sync so a long-lived queue worker cannot accumulate
     * one entry per address it has ever seen.
     *
     * @var array<string, true>
     */
    protected static array $handledThisRun = [];

    public static function forgetHandledThisRun(): void
    {
        static::$handledThisRun = [];
    }

    /**
     * Record (or refresh) a contact from an observed email address, e.g. seen
     * on an inbound message or an outbound send.
     */
    public static function remember(int $userId, string $email, ?string $name = null): void
    {
        $email = trim(strtolower($email));

        if ($email === '' || ! str_contains($email, '@')) {
            return;
        }

        $key = $userId.':'.$email;

        // A name arriving later in the same run for an address already dealt
        // with is picked up on the next one. The alternative is a query per
        // sighting, which is the cost this exists to avoid.
        if (isset(static::$handledThisRun[$key])) {
            return;
        }

        static::$handledThisRun[$key] = true;

        $contact = static::firstOrNew(['user_id' => $userId, 'email' => $email]);

        if (! $contact->needsWriting($name)) {
            return;
        }

        $contact->name = $name ?: $contact->name;
        $contact->last_seen_at = now();
        $contact->save();
    }

    /**
     * A brand new contact, one whose name we can now fill in, or one whose
     * last sighting has gone stale.
     */
    protected function needsWriting(?string $name): bool
    {
        if (! $this->exists) {
            return true;
        }

        if ($name && ! $this->name) {
            return true;
        }

        return $this->last_seen_at === null
            || $this->last_seen_at->addHours(self::FRESH_FOR_HOURS)->isPast();
    }
}
