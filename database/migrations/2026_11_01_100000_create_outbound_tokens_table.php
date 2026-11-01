<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bearer tokens for other apps that send mail through zero (ZERO-131). Only
 * the sha256 of a token is stored. A token is bound to one sending account and
 * the account's owner is the owner of everything it sends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::table('outgoing_messages', function (Blueprint $table) {
            // Messages that came in through the API have no composer behind
            // them, so the user-facing paths skip them. The flag is separate
            // from the token id because the id is nulled when the token goes
            // (its account was deleted), and that must not turn the message
            // into one that looks composed.
            $table->boolean('via_api')->default(false);
            $table->foreignId('outbound_token_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outgoing_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outbound_token_id');
            $table->dropColumn('via_api');
        });

        Schema::dropIfExists('outbound_tokens');
    }
};
