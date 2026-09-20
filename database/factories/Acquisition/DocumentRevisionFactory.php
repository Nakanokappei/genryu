<?php

namespace Database\Factories\Acquisition;

use App\Acquisition\Domain\Models\Document;
use App\Acquisition\Domain\Models\DocumentRevision;
use App\Acquisition\Domain\Models\RawArtifact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRevision>
 */
class DocumentRevisionFactory extends Factory
{
    protected $model = DocumentRevision::class;

    /**
     * Define the model's default state. content_hash is a fresh random hash;
     * tests that care about the RAW link set it from the artifact's sha256.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'revision_no' => 1,
            'raw_artifact_id' => RawArtifact::factory(),
            'content_hash' => hash('sha256', fake()->unique()->uuid()),
            'detected_at' => now(),
        ];
    }
}
