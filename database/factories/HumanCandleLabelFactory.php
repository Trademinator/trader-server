<?php

namespace Database\Factories;

use App\Models\HumanCandleLabel;
use App\Models\HumanTrainingSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HumanCandleLabel>
 */
class HumanCandleLabelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['snapshot_id' => HumanTrainingSnapshot::factory(), 'trainer_id' => User::factory(),
            'action' => fake()->randomElement(['buy', 'hold', 'sell'])];
    }
}
