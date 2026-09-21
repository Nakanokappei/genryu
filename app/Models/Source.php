<?php

namespace App\Models;

use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 情報源 (UI: "Sources"): an official site whose update list we watch.
 *
 * @property array<string, mixed>|null $list_config HTML list settings (App\Actions\FetchUpdates::LIST_CONFIG_KEYS)
 */
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    protected $fillable = ['name', 'url', 'feed_url', 'list_config', 'notes', 'fetched_at'];

    protected function casts(): array
    {
        return ['fetched_at' => 'datetime', 'list_config' => 'array'];
    }

    /** @return HasMany<UpdateEntry, $this> */
    public function updateEntries(): HasMany
    {
        return $this->hasMany(UpdateEntry::class);
    }
}
