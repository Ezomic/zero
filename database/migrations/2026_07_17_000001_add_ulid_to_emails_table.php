<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->string('ulid', 26)->nullable()->after('id');
        });

        // Backfill a durable, logical-message ULID. Rows that share a
        // (mail_account_id, message_id) are the *same* message seen in
        // different folders, so they must share one ULID; rows without a
        // message_id get their own.
        DB::table('emails')
            ->whereNotNull('message_id')
            ->select('mail_account_id', 'message_id')
            ->distinct()
            ->get()
            ->each(function ($group): void {
                DB::table('emails')
                    ->where('mail_account_id', $group->mail_account_id)
                    ->where('message_id', $group->message_id)
                    ->update(['ulid' => (string) Str::ulid()]);
            });

        DB::table('emails')->whereNull('ulid')->orderBy('id')->each(function ($row): void {
            DB::table('emails')->where('id', $row->id)->update(['ulid' => (string) Str::ulid()]);
        });

        // Not unique: the same logical message stored in several folders
        // deliberately shares one ULID (see ImapSyncService::storeMessage),
        // so this is a plain lookup index, not a uniqueness guarantee.
        Schema::table('emails', function (Blueprint $table) {
            $table->string('ulid', 26)->nullable(false)->change();
            $table->index('ulid');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropIndex(['ulid']);
            $table->dropColumn('ulid');
        });
    }
};
