<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per muted conversation.
 *
 * A column on `emails` would have been the smaller change, but a mute has to
 * be readable *before* the message that belongs to the thread exists: the
 * whole point is that the next reply never reaches the inbox. A table keyed
 * by thread answers that without needing a sibling row to already be there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('muted_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            // thread_id is scoped to the account, matching how every other
            // thread query in the app is written.
            $table->string('thread_id');
            $table->timestamps();

            $table->unique(['mail_account_id', 'thread_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('muted_threads');
    }
};
