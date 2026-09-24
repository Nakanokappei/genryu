<?php

namespace App\Models;

use Database\Factories\PromptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A version of a prompt a stage runs with (UI: プロンプト版): the text of a
 * layer of the editorial policy as it was when a run used it, numbered
 * per name (content_filtering for the スクリーニング, structuring for the
 * 素材情報), with its hash. The text is edited on its screen
 * (EditorialPolicy); a run pins the version it used so results, cache
 * figures and cost can be compared before and after a change. A changed
 * prompt also changes the cached prefix.
 */
class Prompt extends Model
{
    /** @use HasFactory<PromptFactory> */
    use HasFactory;

    protected $fillable = ['name', 'version', 'hash', 'text', 'activated_at'];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime'];
    }

    /**
     * The version in force for a name: the latest one when its text is
     * the given text, else a new version numbered after it.
     */
    public static function current(string $name, string $text): self
    {
        $hash = hash('sha256', $text);
        $latest = static::query()->where('name', $name)->orderByDesc('version')->first();

        if ($latest !== null && $latest->hash === $hash) {
            return $latest;
        }

        return static::query()->create([
            'name' => $name,
            'version' => ($latest === null ? 0 : $latest->version) + 1,
            'hash' => $hash,
            'text' => $text,
            'activated_at' => now(),
        ]);
    }

    /** The version in force for a layer of the editorial policy, as its screen holds it now. */
    public static function forLayer(string $layer): self
    {
        return self::current($layer, EditorialPolicy::bodyFor($layer));
    }

    /** The text of a pinned version, which a run cannot do without. */
    public static function textOf(?self $prompt, string $layer): string
    {
        $text = (string) $prompt?->text;

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
