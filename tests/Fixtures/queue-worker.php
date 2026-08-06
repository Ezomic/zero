<?php

/**
 * One worker process contending for jobs, shaped like DatabaseQueue::pop():
 * open a transaction, SELECT the next unreserved job, then UPDATE it.
 *
 * Lives as a standalone script because the contention it demonstrates only
 * exists between separate connections running at the same time — a
 * single-threaded test cannot produce it, since the first transaction can
 * never commit while the second is blocked waiting for it.
 *
 * argv: <database file> <transaction mode> <worker id> <attempts>
 */
[, $file, $mode, $worker, $attempts] = $argv;

$pdo = new PDO("sqlite:{$file}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode=WAL');
$pdo->exec('PRAGMA busy_timeout=5000');

$popped = 0;
$failed = 0;

for ($i = 0; $i < (int) $attempts; $i++) {
    try {
        $pdo->exec("BEGIN {$mode} TRANSACTION");

        $row = $pdo->query('SELECT id FROM jobs WHERE reserved_at IS NULL ORDER BY id LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);

        if (! $row) {
            $pdo->exec('ROLLBACK');
            break;
        }

        $pdo->exec("UPDATE jobs SET reserved_at = {$worker} WHERE id = {$row['id']}");
        $pdo->exec('COMMIT');
        $popped++;
    } catch (Throwable) {
        $failed++;

        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
        }
    }
}

echo json_encode(['popped' => $popped, 'failed' => $failed]);
