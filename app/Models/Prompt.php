<?php

namespace App\Models;

use Database\Factories\PromptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * プロンプト版 (UI "Prompt version"): a layer's text as a run used it,
 * numbered per layer, with its hash; runs pin the version they used.
 */
class Prompt extends Model
{
    /** @use HasFactory<PromptFactory> */
    use HasFactory;

    protected $fillable = ['layer', 'version', 'hash', 'text', 'activated_at'];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime'];
    }

    /** The latest version of a layer if its text matches, else a new version. */
    public static function current(string $layer, string $text): self
    {
        $hash = hash('sha256', $text);
        $latest = static::query()->where('layer', $layer)->orderByDesc('version')->first();

        // Same text: reuse the latest version.
        if ($latest !== null && $latest->hash === $hash) {
            return $latest;
        }

        return static::query()->create([
            'layer' => $layer,
            'version' => ($latest === null ? 0 : $latest->version) + 1,
            'hash' => $hash,
            'text' => $text,
            'activated_at' => now(),
        ]);
    }

    /** The version for a layer's current body. */
    public static function forLayer(string $layer): self
    {
        return self::current($layer, EditorialPolicy::bodyFor($layer));
    }

    /** A pinned version's text; throws when it is empty. */
    public static function textOf(?self $prompt, string $layer): string
    {
        $text = (string) $prompt?->text;

        // A run cannot go without its prompt.
        if (trim($text) === '') {
            throw new RuntimeException(__('The :layer layer of the editorial policy is empty.', ['layer' => __(EditorialPolicy::LAYER_LABELS[$layer] ?? $layer)]));
        }

        return $text;
    }

    /** @return HasMany<Screening, $this> */
    public function screenings(): HasMany
    {
        return $this->hasMany(Screening::class);
    }

    /** @return HasMany<Material, $this> */
    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }
}
