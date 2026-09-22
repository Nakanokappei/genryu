<?php

namespace Database\Factories;

use App\Models\ScreeningPrompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScreeningPrompt> */
class ScreeningPromptFactory extends Factory
{
    public function definition(): array
    {
        $text = fake()->paragraph();

        return [
            'name' => 'content_filtering',
            // Versions are numbered per name, so the next free one.
            'version' => (int) ScreeningPrompt::query()->where('name', 'content_filtering')->max('version') + 1,
            'hash' => hash('sha256', $text),
            'text' => $text,
            'activated_at' => now(),
        ];
    }
}
