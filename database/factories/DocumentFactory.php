<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
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

    /**
     * An entry whose document has been fetched and read into Markdown.
     */
    public function fetched(): static
    {
        return $this->state(fn (): array => [
            'format' => 'html',
            'original_path' => null,
            'markdown' => '# '.fake()->sentence()."\n\n".fake()->paragraph(),
            'fetched_at' => now(),
            'status' => 'fetched',
        ]);
    }
}
