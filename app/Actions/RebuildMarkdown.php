<?php

namespace App\Actions;

use App\Models\Source;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 原本から Markdown を作り直す (UI: "Rebuild Markdown from the originals"):
 * read the document of every document of a source again from the
 * original kept on the local disk, with the source's current document
 * settings and the current Markdown rules, without touching the site. An
 * entry whose settings no longer match is left as it is and counted, so
 * the operator can fetch it again (which lets the agent propose new
 * settings).
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

        foreach ($source->documents()->whereNull('excluded_by')->whereNotNull('original_path')->get() as $document) {
            if (! Storage::disk('local')->exists((string) $document->original_path)) {
                continue;
            }

            try {
                $body = Storage::disk('local')->get((string) $document->original_path);
                $markdown = $document->format === 'pdf'
                    ? $this->read->pdf($body, $document->title)
                    : $this->read->html($body, $source->document_settings ?? [], $document->url, $document->title);

                $document->update(['markdown' => $markdown, 'status' => 'fetched', 'status_message' => __('Markdown rebuilt from the original.')]);
                $rebuilt++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return ['rebuilt' => $rebuilt, 'failed' => $failed];
    }
}
