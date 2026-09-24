<?php

namespace App\Jobs;

use App\Actions\ProposeDecision;
use App\Actions\ReviseDocumentSettings;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\Prompt;
use App\Models\Screening;
use App\OpenAi\Usage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * スクリーニング (UI: "Screening", the Editorial Screening Gate): in the
 * background, have the agent read a fetched document's Markdown with the
 * content filtering prompt and decide 採用 / 不採用 / 要確認. The run is
 * kept as a screening row with the prompt version, the model, the
 * tokens and the estimated cost, and becomes the document's latest
 * screening. Nobody reviews by hand: a 要確認 from the first pass gets
 * one second pass by the next model up, which decides 採用 or 不採用; a
 * 不採用 is final and the document is not sent on to the detailed
 * analysis (App\Jobs\ExtractMaterial refuses it). A 不採用 of a document
 * whose body came out short is suspect, though — a teaser reads like
 * nothing worth adopting — so the source's document settings are
 * revised from that document's original, and when that yields a real
 * body, the cured documents are screened again. The outcome lands on
 * the screening (status 判定中 / 判定済み / 失敗) so the screens can show it.
 * A document adopted on the summary its feed gave (format feed) has its
 * full text fetched (App\Jobs\FetchDocument).
 */
class ScreenDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public Screening $screening) {}

    /**
     * Queue the screening of a document with the prompt as it is now and
     * the model chosen for the content filtering (or one named here, to
     * screen a document again with another model): the run appears at
     * once as 判定中 and is the document's latest screening.
     */
    public static function queueFor(Document $document, ?string $model = null, int $pass = 1): Screening
    {
        $screening = Screening::query()->create([
            'document_id' => $document->id,
            'document_revision_id' => $document->recordRevision()?->id,
            'prompt_id' => Prompt::current('content_filtering', EditorialPolicy::bodyFor('content_filtering'))->id,
            'model' => $model ?? EditorialPolicy::modelFor('content_filtering'),
            'pass' => $pass,
            'status' => 'screening',
        ]);
        $document->update(['screening_id' => $screening->id]);

        self::dispatch($screening);

        return $screening;
    }

    public function handle(ProposeDecision $propose, ReviseDocumentSettings $revise): void
    {
        $screening = $this->screening;
        $document = $screening->document;

        try {
            if ($document->excluded_by !== null) {
                throw new RuntimeException(__('The document is excluded by the title filter.'));
            }

            if ($document->isLeftOut()) {
                throw new RuntimeException(__('The semantic filter left this document out (likeness :likeness).', ['likeness' => sprintf('%+.3f', $document->likeness)]));
            }

            // The text read is the revision pinned when the run was queued, not whatever the document holds by now.
            $markdown = $screening->revision !== null ? $screening->revision->markdown : (string) $document->markdown;

            if ($document->status !== 'fetched' || $markdown === '') {
                throw new RuntimeException(__('The document has not been fetched yet.'));
            }

            $prompt = $screening->prompt->text;

            if (trim($prompt) === '') {
                throw new RuntimeException(__('The content filtering prompt is empty.'));
            }

            $result = $propose($prompt, $screening->model, $markdown, $screening->pass);

            $screening->update([
                ...$result,
                ...Usage::estimatedCost($screening->model, $result),
                'status' => 'screened',
                'status_message' => null,
            ]);
        } catch (Throwable $exception) {
            $screening->update(['status' => 'failed', 'status_message' => mb_substr(mb_scrub($exception->getMessage(), 'UTF-8'), 0, 1000)]);

            return;
        }

        // 要確認 from the first pass: one second pass, by the next model up, which decides.
        if ($screening->decision === 'review' && $screening->pass === 1) {
            self::queueFor($document, EditorialPolicy::nextModelUp($screening->model), 2);

            return;
        }

        // 採用 on the summary from the feed: now the full text is worth its fetch.
        if ($document->refresh()->wantsFullText()) {
            FetchDocument::queueFor($document);

            return;
        }

        // 不採用 with a short body: the settings, not the document, may be at fault.
        if ($screening->decision === 'reject' && $document->refresh()->hasShortBody()) {
            $this->reviseSettings($document, $revise);
        }
    }

    /**
     * Revise the source's document settings from this document's original
     * and, when a real body comes out, screen the cured documents again
     * (this one included); what happened is noted on the screening.
     */
    private function reviseSettings(Document $document, ReviseDocumentSettings $revise): void
    {
        try {
            $result = $revise($document->source, $document);
        } catch (Throwable $exception) {
            $this->screening->update(['status_message' => __('Short body; the document settings could not be revised: :reason', ['reason' => mb_substr(mb_scrub($exception->getMessage(), 'UTF-8'), 0, 500)])]);

            return;
        }

        $this->screening->update(['status_message' => __('Short body; the document settings were revised (content: :content) and :grown documents screened again.', ['content' => $result['settings']['content'], 'grown' => count($result['grown'])])]);

        Document::query()->whereIn('id', $result['grown'])->get()->each(fn (Document $grown) => self::queueFor($grown));
    }
}
