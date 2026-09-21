<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Article> */
class ArticleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'material_id' => Material::factory(),
            'title' => fake()->sentence(),
            'body' => fake()->paragraphs(2, true),
            'status' => 'draft',
            'published_at' => null,
        ];
    }
}
