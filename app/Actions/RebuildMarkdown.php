<?php

namespace App\Actions;

use App\Models\Document;
use App\Models\Source;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 原本から Markdown を作り直す (UI: "Rebuild Markdown from the originals"):
 * read every document of a source again from the original kept on the
 * local disk, with the source's current document settings and the
 * current Markdown rules, without touching the site. A document whose
 * settings no longer match is left as it is and counted, so the operator
 * can fetch it again (which lets the agent propose new settings).
 */
class RebuildMarkdown
{
    public function __construct(private ReadDocument $read) {}

    /**
     * @return array{rebuilt: int, failed: int}
     */
    public function __invoke(Source $source): array
    {
        $rebuilt = 0;
        $failed = 0;

        foreach (Document::query()->whereHas('updateEntry', fn ($query) => $query->where('source_id', $source->id)->whereNull('excluded_by'))->get() as $document) {
            if ($document->original_path === null || ! Storage::disk('local')->exists($document->original_path)) {
                continue;
            }

            try {
                $body = Storage::disk('local')->get($document->original_path);
                $markdown = $document->format === 'pdf'
                    ? $this->read->pdf($body)
                    : $this->read->html($body, $source->document_config ?? [], $document->url, $document->title);

                $document->update(['markdown' => $markdown, 'status' => 'fetched', 'status_message' => __('Markdown rebuilt from the original.')]);
                $rebuilt++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return ['rebuilt' => $rebuilt, 'failed' => $failed];
    }
}
