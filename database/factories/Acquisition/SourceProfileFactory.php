<?php

namespace Database\Factories\Acquisition;

use App\Acquisition\Domain\Enums\ProfileStatus;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Domain\Models\SourceProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceProfile>
 */
class SourceProfileFactory extends Factory
{
    protected $model = SourceProfile::class;

    /**
     * Define the model's default state. The profile_json is a placeholder;
     * schema validation arrives with the Storage Tool in Milestone 2.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_id' => Source::factory(),
            'version' => 1,
            'schema_version' => 1,
            'status' => ProfileStatus::PendingApproval,
            'profile_json' => ['schema_version' => 1],
        ];
    }

    /**
     * Mark the profile as the approved, active version.
     */
    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => ProfileStatus::Active,
            'approved_at' => now(),
            'approved_by' => 'tests',
        ]);
    }
}
