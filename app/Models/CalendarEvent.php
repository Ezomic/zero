<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A local record of an event this app created in Chronos from a message.
 * Chronos owns the event itself; this exists so the reading pane can link to
 * what was already created rather than offering a blind re-create (ZERO-94).
 */
class CalendarEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_id',
        'remote_id',
        'url',
        'title',
        'starts_at',
        'ends_at',
        'timezone',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /** @return BelongsTo<Email, $this> */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /**
     * Times are stored in UTC like every other timestamp here; `timezone` is
     * the zone the event was actually created for, which is the one worth
     * showing it in.
     */
    public function localStart(): CarbonInterface
    {
        return $this->starts_at->setTimezone($this->timezone);
    }
}
