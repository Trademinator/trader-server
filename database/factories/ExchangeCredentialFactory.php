<?php

namespace Database\Factories;

use App\Models\ExchangeCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExchangeCredential> */
class ExchangeCredentialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'credentials' => ['apiKey' => 'test-key-'.fake()->uuid(), 'secret' => 'test-secret-'.fake()->uuid()],
            'is_shared' => false,
        ];
    }
}
