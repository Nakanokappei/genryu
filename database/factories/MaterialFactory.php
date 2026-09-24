<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Material> */
class MaterialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'document_id' => Document::factory()->fetched(),
            'parts' => ['summary' => fake()->sentence(), 'topics' => [fake()->word()]],
            'status' => 'extracted',
        ];
    }
}
