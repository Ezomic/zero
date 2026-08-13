<?php

namespace Database\Factories;

use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedSearch>
 */
class SavedSearchFactory extends Factory
{
    protected $model = SavedSearch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'query' => fake()->word(),
            'mail_account_id' => null,
            'folder' => null,
            'archived' => false,
            'starred' => false,
            'position' => 0,
        ];
    }
}
