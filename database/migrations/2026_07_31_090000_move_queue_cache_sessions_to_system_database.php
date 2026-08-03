<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the queue, cache and session tables out of the mail database and into
 * their own SQLite file (ZERO-80). Rows are carried across rather than
 * recreated: the queue can be holding thousands of unapplied mirror-backs, and
 * dropping them would silently discard user actions.
 */
return new class extends Migration
{
    private const SYSTEM = 'sqlite_system';

    public function up(): void
    {
        $this->ensureSystemDatabaseFileExists();

        $this->createTablesOn(self::SYSTEM);

        foreach (array_keys($this->blueprints()) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->copyTable($table, from: null, to: self::SYSTEM);

            Schema::drop($table);
        }
    }

    public function down(): void
    {
        $this->createTablesOn(null);

        foreach (array_keys($this->blueprints()) as $table) {
            if (! Schema::connection(self::SYSTEM)->hasTable($table)) {
                continue;
            }

            $this->copyTable($table, from: self::SYSTEM, to: null);

            Schema::connection(self::SYSTEM)->drop($table);
        }
    }

    /**
     * The SQLite driver will not create a missing database file, and the
     * connection is opened the moment the first query runs against it.
     */
    private function ensureSystemDatabaseFileExists(): void
    {
        $path = config('database.connections.'.self::SYSTEM.'.database');

        if (! is_string($path) || $path === ':memory:' || $path === '' || file_exists($path)) {
            return;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        touch($path);
    }

    private function createTablesOn(?string $connection): void
    {
        $schema = Schema::connection($connection);

        foreach ($this->blueprints() as $table => $definition) {
            if (! $schema->hasTable($table)) {
                $schema->create($table, $definition);
            }
        }
    }

    /**
     * Copies in chunks so a large `jobs` table doesn't have to be held in
     * memory all at once, then refuses to drop the source unless every row
     * arrived.
     *
     * Re-runnable on purpose. A partial failure leaves rows already sitting in
     * the destination, so a plain insert would collide on the primary key and
     * an exact row-count check would fail forever, leaving a half-moved
     * database recoverable only by hand. insertOrIgnore skips what already
     * arrived, and the check asks whether every source row is now present
     * rather than whether the destination matches exactly.
     */
    private function copyTable(string $table, ?string $from, ?string $to): void
    {
        $source = DB::connection($from);
        $destination = DB::connection($to);

        $expected = $source->table($table)->count();

        if ($expected > 0) {
            $source->table($table)->orderBy($this->orderColumn($table))->chunk(500, function ($rows) use ($destination, $table) {
                $destination->table($table)->insertOrIgnore(array_map(fn ($row) => (array) $row, $rows->all()));
            });
        }

        $copied = $destination->table($table)->count();

        if ($copied < $expected) {
            throw new RuntimeException("Copying {$table} moved {$copied} of {$expected} rows; aborting so the source is left intact.");
        }
    }

    private function orderColumn(string $table): string
    {
        return match ($table) {
            'jobs', 'failed_jobs' => 'id',
            'job_batches', 'sessions' => 'id',
            default => 'key',
        };
    }

    /** @return array<string, callable(Blueprint): void> */
    private function blueprints(): array
    {
        return [
            'jobs' => function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedSmallInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            },
            'job_batches' => function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            },
            'failed_jobs' => function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->string('connection');
                $table->string('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();

                $table->index(['connection', 'queue', 'failed_at']);
            },
            'cache' => function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->bigInteger('expiration')->index();
            },
            'cache_locks' => function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->bigInteger('expiration')->index();
            },
            'sessions' => function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            },
        ];
    }
};
