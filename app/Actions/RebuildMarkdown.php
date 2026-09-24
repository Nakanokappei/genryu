<?php

namespace App\Actions;

use App\Models\Source;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 原本から Markdown を作り直す (UI "Rebuild Markdown from the originals"):
 * re-reads every document of a source from its original on disk with the
 * current settings; one that no longer reads is left as it is and counted.
 */
class RebuildMarkdown
{
    public function __construct(private ReadDocument $read) {}

    /**
     * Rebuilds the source's documents and returns the counts.
     *
     * @return array{rebuilt: int, failed: int}
     */
    public function __invoke(Source $source): array
    {
        $rebuilt = 0;
        $failed = 0;

        foreach ($source->documents()->whereNull('excluded_by')->whereNotNull('original_path')->get() as $document) {
            // Original missing on disk.
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
                // Settings no longer read it: leave it as it is.
                $failed++;
            }
        }

        return ['rebuilt' => $rebuilt, 'failed' => $failed];
    }
}
