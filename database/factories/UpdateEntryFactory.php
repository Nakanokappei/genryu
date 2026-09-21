<?php

namespace Database\Factories;

use App\Models\Source;
use App\Models\UpdateEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UpdateEntry> */
class UpdateEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_id' => Source::factory(),
            'title' => fake()->sentence(),
            'url' => fake()->url(),
            'published_at' => fake()->date(),
        ];
    }
}
