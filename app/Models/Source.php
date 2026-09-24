<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 情報源 (UI "Sources"): an official site whose update list we watch.
 * Status configuring / configured / failed.
 *
 * @property array<string, mixed>|null $html_list_settings App\Crawl\HtmlList::SETTING_KEYS
 * @property array<string, mixed>|null $json_list_settings App\Crawl\JsonList::SETTING_KEYS
 * @property array<string, mixed>|null $document_settings App\Actions\ReadDocument::DOCUMENT_SETTING_KEYS
 * @property string|null $full_text_link 全文へのリンク: CSS selectors, one per line, tried in order
 * @property CarbonImmutable|null $favicon_modified_at the favicon's Last-Modified
 */
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    protected $fillable = ['name', 'url', 'favicon_path', 'favicon_url', 'favicon_modified_at', 'feed_url', 'html_list_settings', 'json_list_settings', 'document_settings', 'full_text_link', 'list_method', 'status', 'status_message', 'notes', 'updates_fetched_at'];

    protected function casts(): array
    {
        return ['updates_fetched_at' => 'datetime', 'favicon_modified_at' => 'datetime', 'html_list_settings' => 'array', 'json_list_settings' => 'array', 'document_settings' => 'array'];
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
