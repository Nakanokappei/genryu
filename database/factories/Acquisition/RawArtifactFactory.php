<?php

namespace Database\Factories\Acquisition;

use App\Acquisition\Domain\Enums\BlobLayer;
use App\Acquisition\Domain\Models\RawArtifact;
use App\Acquisition\Infrastructure\BlobStorage\FilesystemBlobStore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RawArtifact>
 */
class RawArtifactFactory extends Factory
{
    protected $model = RawArtifact::class;

    /**
     * Define the model's default state. The row describes bytes that were
     * never actually stored; tests that need real bytes go through the
     * BlobStore and pass its BlobRef in.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $body = fake()->unique()->paragraph();
        $sha256 = hash('sha256', $body);

        return [
            'blob_uri' => FilesystemBlobStore::SCHEME.FilesystemBlobStore::keyFor(BlobLayer::Raw, $sha256),
            'sha256' => $sha256,
            'bytes' => strlen($body),
            'media_type' => 'text/html',
        ];
    }
}
