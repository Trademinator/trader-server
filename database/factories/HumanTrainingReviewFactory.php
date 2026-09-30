<?php

namespace Database\Factories;

use App\Models\HumanTrainingReview;
use App\Models\HumanTrainingSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HumanTrainingReview>
 */
class HumanTrainingReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['snapshot_id' => HumanTrainingSnapshot::factory(), 'trainer_id' => User::factory(),
            'shown_at' => now()->subMinute(), 'expires_at' => now()->addHour()];
    }
}
