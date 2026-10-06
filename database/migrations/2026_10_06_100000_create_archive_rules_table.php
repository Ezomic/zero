<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rules that archive matching new INBOX mail on arrival, and the column that
 * records which rule did it so each one can be listed and undone (ZERO-127).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // No foreign key, as with saved searches: a removed account should
            // leave the rule matching nothing rather than widening it to all.
            $table->unsignedBigInteger('mail_account_id')->nullable();
            $table->string('kind', 10);
            $table->string('value');
            $table->timestamps();

            $table->unique(['user_id', 'mail_account_id', 'kind', 'value']);
        });

        Schema::table('emails', function (Blueprint $table) {
            $table->unsignedBigInteger('archived_by_rule_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropColumn('archived_by_rule_id');
        });

        Schema::dropIfExists('archive_rules');
    }
};
