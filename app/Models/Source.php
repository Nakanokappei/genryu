<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 情報源 (UI: "Sources"): an official site whose update list we watch.
 *
 * @property array<string, mixed>|null $list_config HTML list settings (App\Actions\FetchUpdates::LIST_CONFIG_KEYS)
 * @property array<string, mixed>|null $json_config JSON list settings (App\Actions\FetchUpdates::JSON_CONFIG_KEYS)
 * @property array<string, mixed>|null $document_config Document settings (App\Actions\ReadDocument::DOCUMENT_CONFIG_KEYS)
 * @property string|null $full_text_link 全文へのリンク: CSS selectors of the link to the full text on a document's page, one per line, tried in order; set, the feed's summary is screened and the full text fetched once adopted
 * @property CarbonImmutable|null $favicon_modified_at Last-Modified of the favicon as fetched (App\Actions\FetchFavicon)
 */
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    protected $fillable = ['name', 'url', 'favicon_path', 'favicon_url', 'favicon_modified_at', 'feed_url', 'list_config', 'json_config', 'document_config', 'full_text_link', 'read_as_html', 'status', 'status_message', 'notes', 'fetched_at'];

    protected function casts(): array
    {
        return ['fetched_at' => 'datetime', 'favicon_modified_at' => 'datetime', 'list_config' => 'array', 'json_config' => 'array', 'document_config' => 'array', 'read_as_html' => 'boolean'];
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
