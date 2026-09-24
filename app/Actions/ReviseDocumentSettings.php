<?php

namespace App\Actions;

use App\Jobs\FetchDocument;
use App\Models\Document;
use App\Models\Source;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * 短い文書から設定を提案し直す (UI "Propose settings again from a short
 * document"): proposes document settings on a 本文が短い document's
 * original, keeps them only if its body is no longer short, and rebuilds
 * the source. Also run by App\Jobs\ScreenDocument on a short-body reject.
 */
class ReviseDocumentSettings
{
    public function __construct(private ProposeDocumentSettings $propose, private ReadDocument $read, private RebuildMarkdown $rebuild) {}

    /**
     * @return array{settings: array<string, string>, chars: int, rebuilt: int, failed: int, grown: list<int>} the settings kept, the body's length on the document proposed from, the rebuild's counts, and the documents whose body is no longer short
     *
     * @throws RuntimeException when no proposal yields a body that is not short, with the best length seen
     */
    public function __invoke(Source $source, Document $document): array
    {
        // Needs an HTML original on disk.
        if ($document->format !== 'html' || $document->original_path === null || ! Storage::disk('local')->exists((string) $document->original_path)) {
            throw new RuntimeException(__('No original on disk to propose from.'));
        }

        $html = Storage::disk('local')->get((string) $document->original_path);
        [$settings, $markdown] = FetchDocument::verify($html, ($this->propose)($html, $document->url), $document, $this->read);

        // Still short: keep the old settings.
        if (mb_strlen($markdown) < Document::SHORT_BODY_CHARS) {
            throw new RuntimeException(__('The agent\'s proposal (content: :content) gives :count characters for ":title", still short. Enter the content selector by hand.', ['content' => $settings['content'], 'count' => mb_strlen($markdown), 'title' => $document->title]));
        }

        // Documents short before the rebuild, to tell which grew.
        $short = $source->documents()->withShortBody()->pluck('id')->all();

        $source->update(['document_settings' => $settings]);
        $result = ($this->rebuild)($source);

        $grown = [];

        // Those no longer short.
        foreach ($source->documents()->whereIn('id', $short)->whereRaw('length(markdown) >= ?', [Document::SHORT_BODY_CHARS])->pluck('id') as $id) {
            $grown[] = (int) $id;
        }

        return ['settings' => $settings, 'chars' => mb_strlen($markdown), ...$result, 'grown' => $grown];
    }
}
