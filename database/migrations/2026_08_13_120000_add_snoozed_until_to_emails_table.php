<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a conversation should come back to the inbox.
 *
 * Null means not snoozed. A time in the future means hidden; a time in the
 * past means due, and the query treats it as visible whether or not the
 * scheduled sweep has cleared it yet, so a missed run cannot strand a
 * conversation (ZERO-114).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->timestamp('snoozed_until')->nullable()->after('is_starred');
            // Every inbox and triage query filters on this, and the sweep
            // looks for due rows across the whole table.
            $table->index('snoozed_until');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropIndex(['snoozed_until']);
            $table->dropColumn('snoozed_until');
        });
    }
};
