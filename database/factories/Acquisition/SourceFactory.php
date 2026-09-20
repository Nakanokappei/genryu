<?php

namespace Database\Factories\Acquisition;

use App\Acquisition\Domain\Enums\HealthStatus;
use App\Acquisition\Domain\Enums\SourceStatus;
use App\Acquisition\Domain\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    protected $model = Source::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = Str::lower(fake()->unique()->lexify('source????'));

        return [
            'key' => $key,
            'name' => Str::upper($key),
            'base_url' => "https://www.{$key}.example/",
            'status' => SourceStatus::Active,
            'health_status' => HealthStatus::Healthy,
        ];
    }
}
