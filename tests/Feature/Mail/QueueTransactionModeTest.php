<?php

namespace Tests\Feature\Mail;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The queue file's transaction mode, verified by contention rather than by
 * reading the config back.
 *
 * Two zero-queue workers run against system.sqlite on production. Under
 * DEFERRED they deadlocked promoting a read to a write and the loser died
 * with "database is locked" (ZERO-84). Asserting the config string alone
 * would not have caught that, because DEFERRED is a perfectly valid setting
 * that only misbehaves under concurrency.
 */
class QueueTransactionModeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/zero-queue-mode-'.uniqid();
        File::makeDirectory($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_the_queue_connection_begins_transactions_immediately(): void
    {
        $this->assertSame('IMMEDIATE', config('database.connections.sqlite_system.transaction_mode'));
    }

    /**
     * Laravel only issues the configured mode on PHP 8.4 and up
     * (SQLiteConnection::executeBeginTransactionStatement); below that it
     * calls PDO::beginTransaction(), which is always DEFERRED.
     */
    public function test_the_runtime_actually_applies_the_configured_mode(): void
    {
        $this->assertTrue(
            version_compare(PHP_VERSION, '8.4.0', '>='),
            'Below PHP 8.4 the transaction_mode setting is ignored and ZERO-84 returns.',
        );
    }

    /** @return array{popped: int, failed: int} */
    private function raceTwoWorkers(string $mode, int $jobs = 20): array
    {
        $file = "{$this->dir}/{$mode}.sqlite";

        $pdo = new \PDO("sqlite:{$file}");
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, reserved_at INT)');

        $insert = $pdo->prepare('INSERT INTO jobs VALUES (?, NULL)');

        for ($i = 1; $i <= $jobs; $i++) {
            $insert->execute([$i]);
        }

        $worker = base_path('tests/Fixtures/queue-worker.php');
        $attempts = (int) ceil($jobs / 2);
        $procs = [];
        $pipes = [];

        foreach ([1, 2] as $id) {
            $procs[$id] = proc_open(
                [PHP_BINARY, $worker, $file, $mode, (string) $id, (string) $attempts],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$id],
            );
        }

        $totals = ['popped' => 0, 'failed' => 0];

        foreach ($procs as $id => $proc) {
            $output = (string) stream_get_contents($pipes[$id][1]);
            fclose($pipes[$id][1]);
            fclose($pipes[$id][2]);
            proc_close($proc);

            $result = json_decode($output, true);
            $this->assertIsArray($result, "worker {$id} produced no result: {$output}");
            $totals['popped'] += $result['popped'];
            $totals['failed'] += $result['failed'];
        }

        return $totals;
    }

    /**
     * Races the mode the queue connection is *actually configured with*, so
     * setting it back to DEFERRED fails here on real contention rather than
     * on a string comparison.
     */
    public function test_two_concurrent_workers_both_pop_jobs_without_locking_each_other_out(): void
    {
        $mode = config('database.connections.sqlite_system.transaction_mode');

        $result = $this->raceTwoWorkers(is_string($mode) ? $mode : 'DEFERRED');

        $this->assertSame(0, $result['failed'], 'no worker should hit "database is locked"');
        $this->assertSame(20, $result['popped'], 'both workers together should drain the queue');
    }

    /**
     * The failure this ticket is about, pinned so the reasoning behind the
     * setting stays visible: swap the mode back and the contention returns.
     */
    public function test_deferred_is_what_locked_the_second_worker_out(): void
    {
        $result = $this->raceTwoWorkers('DEFERRED');

        $this->assertGreaterThan(0, $result['failed'], 'DEFERRED is expected to lock a worker out');
        $this->assertLessThan(20, $result['popped'], 'and to leave part of the queue unclaimed');
    }
}
