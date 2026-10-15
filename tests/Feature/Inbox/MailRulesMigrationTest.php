<?php

namespace Tests\Feature\Inbox;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MailRulesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        $migration = require database_path('migrations/2026_10_15_100000_replace_archive_rules_with_mail_rules.php');
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    public function test_existing_archive_rules_keep_their_ids_and_become_archive_from_rules(): void
    {
        $user = User::factory()->create();
        $migration = $this->migration();
        $migration->down();

        $now = now();
        DB::table('archive_rules')->insert([
            ['id' => 7, 'user_id' => $user->id, 'mail_account_id' => null, 'kind' => 'address', 'value' => 'a@noisy.test', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 9, 'user_id' => $user->id, 'mail_account_id' => 3, 'kind' => 'domain', 'value' => 'noisy.test', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $migration->up();

        $this->assertFalse(Schema::hasTable('archive_rules'));
        $rows = DB::table('mail_rules')->orderBy('position')->get();
        $this->assertSame([7, 9], $rows->pluck('id')->map(fn (mixed $id): int => (int) (is_numeric($id) ? $id : 0))->all());
        $this->assertSame([1, 2], $rows->pluck('position')->map(fn (mixed $p): int => (int) (is_numeric($p) ? $p : 0))->all());
        $this->assertSame(['is', 'domain'], $rows->pluck('operator')->all());
        $this->assertSame(['from', 'from'], $rows->pluck('field')->all());
        $this->assertSame(['archive', 'archive'], $rows->pluck('action')->all());
        $this->assertSame([null, 3], $rows->pluck('mail_account_id')->all());
    }

    public function test_rolling_back_keeps_only_what_the_old_table_could_hold(): void
    {
        $user = User::factory()->create();
        $now = now();
        DB::table('mail_rules')->insert([
            ['id' => 1, 'user_id' => $user->id, 'position' => 1, 'field' => 'from', 'operator' => 'domain', 'value' => 'noisy.test', 'action' => 'archive', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'user_id' => $user->id, 'position' => 2, 'field' => 'subject', 'operator' => 'contains', 'value' => 'x', 'action' => 'archive', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'user_id' => $user->id, 'position' => 3, 'field' => 'from', 'operator' => 'is', 'value' => 'a@b.test', 'action' => 'star', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $this->migration()->down();

        $this->assertSame([1], DB::table('archive_rules')->pluck('id')->map(fn (mixed $id): int => (int) (is_numeric($id) ? $id : 0))->all());
        $this->assertSame('domain', DB::table('archive_rules')->value('kind'));
    }
}
