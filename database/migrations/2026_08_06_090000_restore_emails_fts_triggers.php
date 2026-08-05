<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Restores the emails_fts triggers and reindexes what was missed.
 *
 * 2026_07_02_000004 created emails_fts plus three triggers on `emails`.
 * 2026_07_17_000001 then ran `->change()` on the ulid column, and SQLite
 * cannot alter a column in place: Laravel's grammar recreates the table and
 * copies the rows over, which takes every trigger attached to the old table
 * with it. The index has been frozen at whatever `emails` held that day ever
 * since, and nothing surfaced it — InboxController only falls back to LIKE
 * when the FTS query *throws*, and a stale index answers happily with
 * partial results (ZERO-98).
 *
 * The trigger bodies are deliberately repeated here rather than shared with
 * the original migration: a migration records what actually ran at a point in
 * time, and editing the old one would not help a database that already
 * applied it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $this->dropTriggers();

        DB::statement('
            CREATE TRIGGER emails_fts_ai AFTER INSERT ON emails BEGIN
                INSERT INTO emails_fts(rowid, subject, from_address, body_text)
                VALUES (new.id, new.subject, new.from_address, new.body_text);
            END
        ');

        DB::statement("
            CREATE TRIGGER emails_fts_ad AFTER DELETE ON emails BEGIN
                INSERT INTO emails_fts(emails_fts, rowid, subject, from_address, body_text)
                VALUES ('delete', old.id, old.subject, old.from_address, old.body_text);
            END
        ");

        DB::statement("
            CREATE TRIGGER emails_fts_au AFTER UPDATE ON emails BEGIN
                INSERT INTO emails_fts(emails_fts, rowid, subject, from_address, body_text)
                VALUES ('delete', old.id, old.subject, old.from_address, old.body_text);
                INSERT INTO emails_fts(rowid, subject, from_address, body_text)
                VALUES (new.id, new.subject, new.from_address, new.body_text);
            END
        ");

        // Everything synced while the triggers were missing is absent from the
        // index. 'rebuild' rereads the whole content table, which is the only
        // way to get those rows back without knowing when the gap started.
        DB::statement("INSERT INTO emails_fts(emails_fts) VALUES('rebuild')");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $this->dropTriggers();
    }

    private function dropTriggers(): void
    {
        foreach (['emails_fts_ai', 'emails_fts_ad', 'emails_fts_au'] as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
