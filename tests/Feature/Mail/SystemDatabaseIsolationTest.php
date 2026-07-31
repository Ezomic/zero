<?php

namespace Tests\Feature\Mail;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The queue, cache and session tables must stay off the mail database. Sharing
 * a file meant a busy sync and the queue worker contended for the same write
 * lock, and the cache write that frees the sync's overlap lock failed exactly
 * when it was needed most (ZERO-80).
 */
class SystemDatabaseIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private const MOVED_TABLES = ['jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions'];

    public function test_queue_cache_and_sessions_are_configured_off_the_default_connection(): void
    {
        $this->assertSame('sqlite_system', config('queue.connections.database.connection'));
        $this->assertSame('sqlite_system', config('cache.stores.database.connection'));
        $this->assertSame('sqlite_system', config('cache.stores.database.lock_connection'));
        $this->assertSame('sqlite_system', config('session.connection'));
    }

    public function test_moved_tables_are_gone_from_the_mail_database(): void
    {
        foreach (self::MOVED_TABLES as $table) {
            $this->assertFalse(
                Schema::hasTable($table),
                "{$table} should no longer exist on the mail database",
            );
        }
    }
}
