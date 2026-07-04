<?php

namespace Database\Factories;

use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailAccount>
 */
class MailAccountFactory extends Factory
{
    protected $model = MailAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email_address' => $this->faker->unique()->safeEmail(),
            'display_name' => $this->faker->name(),
            'color' => $this->faker->randomElement(MailAccount::COLOR_PALETTE),
            'provider' => MailAccount::PROVIDER_IMAP,
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $this->faker->safeEmail(),
            'imap_password' => 'secret',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $this->faker->safeEmail(),
            'smtp_password' => 'secret',
            'is_active' => true,
            'sync_status' => 'idle',
        ];
    }

    public function gmail(): static
    {
        return $this->state([
            'provider' => MailAccount::PROVIDER_GMAIL,
            'imap_host' => 'imap.gmail.com',
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'imap_password' => null,
            'smtp_password' => null,
        ]);
    }

    public function outlook(): static
    {
        return $this->state([
            'provider' => MailAccount::PROVIDER_OUTLOOK,
            'imap_host' => 'outlook.office365.com',
            'smtp_host' => 'smtp.office365.com',
            'smtp_port' => 587,
            'imap_password' => null,
            'smtp_password' => null,
        ]);
    }
}
