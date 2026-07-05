<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('mail_folders', function (Blueprint $table) {
            $table->unsignedBigInteger('last_uid')->default(0)->after('remote_path');
            $table->unsignedBigInteger('uid_validity')->default(0)->after('last_uid');
        });
    }

    public function down(): void
    {
        Schema::table('mail_folders', function (Blueprint $table) {
            $table->dropColumn(['last_uid', 'uid_validity']);
        });
    }
};
