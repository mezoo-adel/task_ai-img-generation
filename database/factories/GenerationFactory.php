<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider_id' => null,
            'key_label' => 'local',
            'prompt' => fake()->sentence(),
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_urls' => [],
            'status' => GenerationStatus::Queued,
            'estimated_min_picos' => 1000000,
            'estimated_max_picos' => 2000000,
            'provider_cost_picos' => null,
            'usage' => null,
            'rates' => null,
            'accepted_at' => null,
            'completed_at' => null,
            'next_check_at' => now(),
            'image_expires_at' => null,
            'image_url' => null,
            'failure_code' => null,
            'attention_reason' => null,
            'submit_attempts' => 0,
            'acceptance_uncertain' => false,
            'recovery_step' => 0,
        ];
    }
}
