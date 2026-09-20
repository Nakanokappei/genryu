<?php

namespace Database\Factories\Acquisition;

use App\Acquisition\Domain\Models\Document;
use App\Acquisition\Domain\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = 'https://www.example.org/news/'.fake()->unique()->slug();

        return [
            'source_id' => Source::factory(),
            'stable_key' => 'url:'.$url,
            'identity_rule' => 'url',
            'canonical_url' => $url,
            'document_type' => 'news',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
