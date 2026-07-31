<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A durable list of local actions still waiting to reach the mail server.
 * Previously this only existed as one queued job per message, which meant
 * every flag change paid for its own IMAP session and there was no way to
 * count what was outstanding without deserializing thousands of payloads
 * (ZERO-78, ZERO-77).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_mirror_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->string('remote_folder_path');
            $table->string('uid');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            // Drives the per-account pending count and oldest-age readout.
            $table->index(['mail_account_id', 'failed_at']);

            // The drain groups by exactly this, so one session can serve every
            // action of a kind against a folder.
            $table->index(['mail_account_id', 'remote_folder_path', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_mirror_actions');
    }
};
