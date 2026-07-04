<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->string('color', 7)->nullable()->after('display_name');
        });

        Schema::table('emails', function (Blueprint $table) {
            $table->string('thread_id')->nullable()->index()->after('message_id');
            $table->string('in_reply_to')->nullable()->after('thread_id');
            $table->text('references_header')->nullable()->after('in_reply_to');
            $table->string('remote_folder_path')->nullable()->after('folder');
            $table->boolean('is_archived')->default(false)->after('is_read');
            $table->boolean('is_deleted')->default(false)->after('is_archived');
        });

        Schema::create('drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mail_account_id')->nullable()->constrained()->nullOnDelete();
            $table->text('to_addresses')->nullable();
            $table->text('cc_addresses')->nullable();
            $table->string('subject')->nullable();
            $table->longText('body')->nullable();
            $table->string('in_reply_to')->nullable();
            $table->text('references_header')->nullable();
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'email']);
        });

        // SQLite FTS5 external-content index over emails, kept in sync via triggers.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("
                CREATE VIRTUAL TABLE IF NOT EXISTS emails_fts USING fts5(
                    subject, from_address, body_text,
                    content='emails', content_rowid='id'
                )
            ");

            DB::statement('
                CREATE TRIGGER IF NOT EXISTS emails_fts_ai AFTER INSERT ON emails BEGIN
                    INSERT INTO emails_fts(rowid, subject, from_address, body_text)
                    VALUES (new.id, new.subject, new.from_address, new.body_text);
                END
            ');

            DB::statement("
                CREATE TRIGGER IF NOT EXISTS emails_fts_ad AFTER DELETE ON emails BEGIN
                    INSERT INTO emails_fts(emails_fts, rowid, subject, from_address, body_text)
                    VALUES ('delete', old.id, old.subject, old.from_address, old.body_text);
                END
            ");

            DB::statement("
                CREATE TRIGGER IF NOT EXISTS emails_fts_au AFTER UPDATE ON emails BEGIN
                    INSERT INTO emails_fts(emails_fts, rowid, subject, from_address, body_text)
                    VALUES ('delete', old.id, old.subject, old.from_address, old.body_text);
                    INSERT INTO emails_fts(rowid, subject, from_address, body_text)
                    VALUES (new.id, new.subject, new.from_address, new.body_text);
                END
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS emails_fts_ai');
            DB::statement('DROP TRIGGER IF EXISTS emails_fts_ad');
            DB::statement('DROP TRIGGER IF EXISTS emails_fts_au');
            DB::statement('DROP TABLE IF EXISTS emails_fts');
        }

        Schema::dropIfExists('contacts');
        Schema::dropIfExists('drafts');

        Schema::table('emails', function (Blueprint $table) {
            $table->dropColumn(['thread_id', 'in_reply_to', 'references_header', 'remote_folder_path', 'is_archived', 'is_deleted']);
        });

        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
