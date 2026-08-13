<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->boolean('is_starred')->default(false)->after('is_read');
            // The Starred view filters on this alone across every account, so
            // it is worth an index of its own rather than riding on another.
            $table->index(['mail_account_id', 'is_starred']);
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropIndex(['mail_account_id', 'is_starred']);
            $table->dropColumn('is_starred');
        });
    }
};
