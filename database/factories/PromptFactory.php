<?php

namespace Database\Factories;

use App\Models\Prompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Prompt> */
class PromptFactory extends Factory
{
    public function definition(): array
    {
        $text = fake()->paragraph();

        return [
            'layer' => 'content_filtering',
            // The layer's next free version.
            'version' => (int) Prompt::query()->where('layer', 'content_filtering')->max('version') + 1,
            'hash' => hash('sha256', $text),
            'text' => $text,
            'activated_at' => now(),
        ];
    }
}
