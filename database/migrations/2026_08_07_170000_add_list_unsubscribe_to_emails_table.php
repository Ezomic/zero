<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zero stored no headers beyond message_id, in_reply_to and
 * references_header, so getting off a mailing list meant hunting for the link
 * in the footer. Captured at sync time from here on; existing mail simply
 * lacks them until it is refetched (ZERO-115).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->text('list_unsubscribe')->nullable()->after('references_header');
            // RFC 8058: its presence is what makes the endpoint safe to POST
            // to without navigating anywhere.
            $table->string('list_unsubscribe_post')->nullable()->after('list_unsubscribe');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropColumn(['list_unsubscribe', 'list_unsubscribe_post']);
        });
    }
};
