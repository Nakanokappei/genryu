<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\UpdateEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'update_entry_id' => UpdateEntry::factory(),
            'title' => fake()->sentence(),
            'url' => fake()->url(),
            'format' => 'html',
            'original_path' => null,
            'markdown' => '# '.fake()->sentence()."\n\n".fake()->paragraph(),
            'fetched_at' => now(),
            'status' => 'fetched',
        ];
    }
}
