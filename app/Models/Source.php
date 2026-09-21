<?php

namespace App\Models;

use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 情報源 (UI: "Sources"): an official site whose update list we watch.
 */
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    protected $fillable = ['name', 'url', 'notes'];

    /** @return HasMany<UpdateEntry, $this> */
    public function updateEntries(): HasMany
    {
        return $this->hasMany(UpdateEntry::class);
    }
}
