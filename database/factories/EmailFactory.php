<?php

namespace Database\Factories;

use App\Models\Email;
use App\Models\MailAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Email>
 */
class EmailFactory extends Factory
{
    protected $model = Email::class;

    public function definition(): array
    {
        return [
            'mail_account_id' => MailAccount::factory(),
            'ulid' => (string) Str::ulid(),
            'message_id' => $this->faker->unique()->uuid().'@example.com',
            'thread_id' => $this->faker->uuid(),
            'folder' => 'INBOX',
            'uid' => (string) $this->faker->unique()->numberBetween(1, 1_000_000),
            'subject' => $this->faker->sentence(),
            'from_address' => $this->faker->safeEmail(),
            'from_name' => $this->faker->name(),
            'to_addresses' => [$this->faker->safeEmail()],
            'body_text' => $this->faker->paragraph(),
            'is_read' => false,
            'is_archived' => false,
            'is_deleted' => false,
            'has_attachments' => false,
            'sent_at' => now(),
        ];
    }
}
