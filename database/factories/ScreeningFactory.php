<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Prompt;
use App\Models\Screening;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Screening> */
class ScreeningFactory extends Factory
{
    public function definition(): array
    {
        return [
            'document_id' => Document::factory()->fetched(),
            'prompt_id' => Prompt::factory(),
            'model' => 'gpt-5.6-terra',
            'status' => 'screened',
            'decision' => 'adopt',
            'reason_class' => 'DEMONSTRATION',
            'evidence' => fake()->sentence(),
            'reason' => fake()->sentence(),
            'input_tokens' => 3000,
            'cached_tokens' => 2000,
            'cache_write_tokens' => 0,
            'output_tokens' => 120,
            'latency_ms' => 1500,
        ];
    }

    /**
     * A screening that is the document's latest: the document points at it.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (Screening $screening) => $screening->document->update(['latest_screening_id' => $screening->id]));
    }

    public function rejected(): static
    {
        return $this->state(['decision' => 'reject', 'reason_class' => 'EVENT_PR']);
    }

    public function review(): static
    {
        return $this->state(['decision' => 'review', 'reason_class' => 'INSUFFICIENT_EVIDENCE']);
    }
}
