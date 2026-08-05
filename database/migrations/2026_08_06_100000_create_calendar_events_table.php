<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained()->cascadeOnDelete();
            // Chronos owns the event; these are a local record of what was
            // sent there, so the reading pane can link to it instead of
            // offering a blind re-create.
            $table->string('remote_id')->nullable();
            $table->string('url')->nullable();
            $table->string('title');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone');
            $table->timestamps();

            $table->index('email_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
