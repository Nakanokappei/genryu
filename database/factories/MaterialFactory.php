<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\UpdateEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Material> */
class MaterialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'update_entry_id' => UpdateEntry::factory()->fetched(),
            'data' => ['summary' => fake()->sentence(), 'topics' => [fake()->word()]],
            'status' => 'extracted',
        ];
    }
}
