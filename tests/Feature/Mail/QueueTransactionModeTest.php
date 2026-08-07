<?php

namespace Tests\Feature\Mail;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The queue file must begin transactions IMMEDIATE.
 *
 * Two zero-queue workers run against system.sqlite on production. Under
 * DEFERRED they deadlocked promoting a read to a write and the loser died
 * with "database is locked", and because SQLite refuses that promotion
 * without consulting the busy handler, the connection's 180s busy_timeout
 * never applied (ZERO-84).
 *
 * These assert the behaviour through Laravel's own connection rather than
 * raw PDO, so they still fail if the framework stops honouring
 * transaction_mode — which it silently does below PHP 8.4, where
 * SQLiteConnection falls back to PDO::beginTransaction() and always gets
 * DEFERRED.
 */
class QueueTransactionModeTest extends TestCase
{
    private string $dir;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs sqlite_system on :memory:, which a second connection
        // cannot open. Point it at a real file so the lock is observable.
        $this->dir = sys_get_temp_dir().'/zero-queue-mode-'.uniqid();
        File::makeDirectory($this->dir, 0755, true);
        $this->file = $this->dir.'/system.sqlite';
        touch($this->file);

        config(['database.connections.sqlite_system.database' => $this->file]);
        DB::purge('sqlite_system');

        DB::connection('sqlite_system')->statement('CREATE TABLE jobs (id INTEGER PRIMARY KEY, reserved_at INTEGER)');
        DB::connection('sqlite_system')->table('jobs')->insert(['id' => 1, 'reserved_at' => null]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite_system');
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_the_queue_connection_is_configured_to_begin_immediately(): void
    {
        $this->assertSame('IMMEDIATE', config('database.connections.sqlite_system.transaction_mode'));
    }

    /**
     * Below PHP 8.4 the setting is ignored entirely, so the assertion above
     * would pass while production still deadlocked.
     */
    public function test_the_runtime_is_new_enough_to_apply_the_configured_mode(): void
    {
        $this->assertTrue(
            version_compare(PHP_VERSION, '8.4.0', '>='),
            'Below PHP 8.4 transaction_mode is ignored and ZERO-84 returns.',
        );
    }

    /**
     * The real check, and deterministic: a transaction opened through Laravel
     * takes the write lock at BEGIN, so a second connection cannot also take
     * it. Under DEFERRED the first transaction holds nothing at BEGIN and the
     * second sails through, which is exactly the state that let two workers
     * both read and then both try to promote.
     *
     * Single-threaded on purpose. An earlier version of this raced two worker
     * subprocesses, which only produced contention when they happened to
     * overlap and so failed about 60% of runs (ZERO-111).
     */
    public function test_a_laravel_transaction_holds_the_write_lock_from_the_start(): void
    {
        DB::connection('sqlite_system')->beginTransaction();

        try {
            $this->assertTrue(
                $this->writeLockIsHeld(),
                'sqlite_system opened a transaction without taking the write lock, so two queue workers can still deadlock promoting a read',
            );
        } finally {
            DB::connection('sqlite_system')->rollBack();
        }
    }

    public function test_the_write_lock_is_released_again_on_rollback(): void
    {
        DB::connection('sqlite_system')->beginTransaction();
        DB::connection('sqlite_system')->rollBack();

        $this->assertFalse($this->writeLockIsHeld(), 'the lock must not outlive the transaction');
    }

    /**
     * Asks a second connection to take the write lock. A short busy_timeout
     * keeps the failing case quick; the answer is the same either way.
     */
    private function writeLockIsHeld(): bool
    {
        $probe = new \PDO('sqlite:'.$this->file);
        $probe->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $probe->exec('PRAGMA busy_timeout=250');

        try {
            $probe->exec('BEGIN IMMEDIATE TRANSACTION');
            $probe->exec('ROLLBACK');

            return false;
        } catch (\PDOException) {
            return true;
        }
    }
}
