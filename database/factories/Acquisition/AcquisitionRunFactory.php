<?php

namespace Database\Factories\Acquisition;

use App\Acquisition\Domain\Enums\RunMode;
use App\Acquisition\Domain\Enums\RunStatus;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcquisitionRun>
 */
class AcquisitionRunFactory extends Factory
{
    protected $model = AcquisitionRun::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mode' => RunMode::Monitoring,
            'source_id' => Source::factory(),
            'status' => RunStatus::Pending,
            'counters' => [],
        ];
    }
}
