<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A named, reusable inbox scope: the query plus the account, folder and
 * archived/starred cut it was saved under (ZERO-120).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('query');
            // No foreign key on purpose: an account or folder that goes away
            // should leave the saved view returning nothing, which is
            // truthful, rather than nulling the column and silently widening
            // the view to every account.
            $table->unsignedBigInteger('mail_account_id')->nullable();
            $table->string('folder')->nullable();
            $table->boolean('archived')->default(false);
            $table->boolean('starred')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'position']);
            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
    }
};
