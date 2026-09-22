<?php

namespace App\Models;

use Database\Factories\ScreeningPromptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A version of the prompt the スクリーニング (UI: "Screening") runs with:
 * the text of the content filtering layer as it was when a screening
 * used it, numbered per name, with its hash. The text is edited on the
 * 文書 screen (EditorialPolicy); a screening pins the version it used so
 * adoption rates, cache figures and cost can be compared before and
 * after a change. A changed prompt also changes the cached prefix.
 */
class ScreeningPrompt extends Model
{
    /** @use HasFactory<ScreeningPromptFactory> */
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

    /** @return HasMany<Screening, $this> */
    public function screenings(): HasMany
    {
        return $this->hasMany(Screening::class);
    }
}
