<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generalises the archive-only rules of ZERO-127 into rules that can also file,
 * star or mark read (ZERO-118). Existing rows keep their ids because
 * emails.archived_by_rule_id points at them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // No foreign key, as with archive rules and saved searches: a
            // removed account leaves the rule matching nothing rather than
            // widening it to every account.
            $table->unsignedBigInteger('mail_account_id')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->string('field', 10)->default('from');
            $table->string('operator', 10)->default('contains');
            $table->string('value');
            $table->string('action', 10)->default('archive');
            $table->string('folder')->nullable();
            $table->boolean('stop_on_match')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'position']);
        });

        $position = 0;

        DB::table('archive_rules')->orderBy('id')->each(function (object $rule) use (&$position): void {
            DB::table('mail_rules')->insert([
                'id' => $rule->id,
                'user_id' => $rule->user_id,
                'mail_account_id' => $rule->mail_account_id,
                'position' => ++$position,
                'field' => 'from',
                'operator' => $rule->kind === 'domain' ? 'domain' : 'is',
                'value' => $rule->value,
                'action' => 'archive',
                'created_at' => $rule->created_at,
                'updated_at' => $rule->updated_at,
            ]);
        });

        Schema::dropIfExists('archive_rules');
    }

    public function down(): void
    {
        Schema::create('archive_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mail_account_id')->nullable();
            $table->string('kind', 10);
            $table->string('value');
            $table->timestamps();

            $table->unique(['user_id', 'mail_account_id', 'kind', 'value']);
        });

        DB::table('mail_rules')
            ->where('field', 'from')
            ->where('action', 'archive')
            ->whereIn('operator', ['is', 'domain'])
            ->orderBy('id')
            ->each(function (object $rule): void {
                DB::table('archive_rules')->insertOrIgnore([
                    'id' => $rule->id,
                    'user_id' => $rule->user_id,
                    'mail_account_id' => $rule->mail_account_id,
                    'kind' => $rule->operator === 'domain' ? 'domain' : 'address',
                    'value' => $rule->value,
                    'created_at' => $rule->created_at,
                    'updated_at' => $rule->updated_at,
                ]);
            });

        Schema::dropIfExists('mail_rules');
    }
};
