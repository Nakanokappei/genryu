<?php

namespace App\Actions;

use App\Jobs\FetchDocument;
use App\Models\Document;
use App\Models\Source;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Revise the document settings of a source from a document whose fetched
 * body came out short (UI: 本文が短い): the agent proposes settings again
 * on that document's original, they are verified on it, kept only when
 * they yield a body no longer short, and then every document of the
 * source is read again from its original. Run by hand from the source
 * (短い文書から設定を提案し直す) and by App\Jobs\ScreenDocument when the
 * screening rejects a document with a short body, since a teaser reads
 * like nothing worth adopting.
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
        if ($document->format !== 'html' || $document->original_path === null || ! Storage::disk('local')->exists((string) $document->original_path)) {
            throw new RuntimeException(__('No original on disk to propose from.'));
        }

        $html = Storage::disk('local')->get((string) $document->original_path);
        [$settings, $markdown] = FetchDocument::verify($html, ($this->propose)($html, $document->url), $document, $this->read);

        if (mb_strlen($markdown) < Document::SHORT_BODY_CHARS) {
            throw new RuntimeException(__('The agent\'s proposal (content: :content) gives :count characters for ":title", still short. Enter the content selector by hand.', ['content' => $settings['content'], 'count' => mb_strlen($markdown), 'title' => $document->title]));
        }

        // The documents short before the rebuild, to tell which ones the new settings cured.
        $short = $source->documents()->withShortBody()->pluck('id')->all();

        $source->update(['document_settings' => $settings]);
        $result = ($this->rebuild)($source);

        $grown = [];

        foreach ($source->documents()->whereIn('id', $short)->whereRaw('length(markdown) >= ?', [Document::SHORT_BODY_CHARS])->pluck('id') as $id) {
            $grown[] = (int) $id;
        }

        return ['settings' => $settings, 'chars' => mb_strlen($markdown), ...$result, 'grown' => $grown];
    }
}
