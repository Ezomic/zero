<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A composed message held for a few seconds before it leaves, so it can be
 * taken back (ZERO-117). The row is the single source of truth for whether the
 * message goes out: the queued job only carries the id and re-reads the status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outgoing_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Nulled rather than cascaded: a message whose account was removed
            // during the hold must fail visibly, not vanish with the account.
            $table->foreignId('mail_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 12)->index();
            $table->timestamp('send_at');
            $table->json('to_addresses');
            $table->json('cc_addresses');
            $table->string('subject');
            $table->text('body');
            $table->string('in_reply_to')->nullable();
            $table->text('references_header')->nullable();
            $table->json('attachments');
            // Deleted when the message is delivered, so a take-back or a failure
            // never costs the user their draft.
            $table->unsignedBigInteger('draft_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('sending_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outgoing_messages');
    }
};
