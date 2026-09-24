<?php

namespace App\Jobs;

use App\Actions\ProposeDecision;
use App\Actions\ReviseDocumentSettings;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\Prompt;
use App\Models\Screening;
use App\OpenAi\Usage;
use App\Support\ErrorMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * スクリーニング (UI: "Screening"): have the agent decide 採用 / 不採用 / 要確認
 * on a document per the content filtering, then act on the decision.
 */
class ScreenDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public Screening $screening) {}

    /** Create a screening (判定中) as the document's latest, pinning revision, prompt and model, and queue it. */
    public static function queueFor(Document $document, ?string $model = null, int $pass = 1): Screening
    {
        $screening = Screening::query()->create([
            'document_id' => $document->id,
            'document_revision_id' => $document->recordRevision()?->id,
            'prompt_id' => Prompt::forLayer('content_filtering')->id,
            'model' => $model ?? EditorialPolicy::modelFor('content_filtering'),
            'pass' => $pass,
            'status' => 'screening',
        ]);
        $document->update(['latest_screening_id' => $screening->id]);

        self::dispatch($screening);

        return $screening;
    }

    /** Screen the document, then queue a second pass, the full text, or a settings revision as the decision calls for. */
    public function handle(ProposeDecision $propose, ReviseDocumentSettings $revise): void
    {
        $screening = $this->screening;
        $document = $screening->document;

        try {
            // Excluded or left out: not screened.
            if ($document->excluded_by !== null) {
                throw new RuntimeException(__('The document is excluded by the title filter.'));
            }

            if ($document->isLeftOut()) {
                throw new RuntimeException(__('The semantic filter left this document out (likeness :likeness).', ['likeness' => sprintf('%+.3f', $document->likeness)]));
            }

            // The pinned revision, else the current Markdown.
            $markdown = $screening->revision !== null ? $screening->revision->markdown : (string) $document->markdown;

            // Only a fetched document with text.
            if ($document->status !== 'fetched' || $markdown === '') {
                throw new RuntimeException(__('The document has not been fetched yet.'));
            }

            $prompt = Prompt::textOf($screening->prompt, 'content_filtering');

            $result = $propose($prompt, $screening->model, $markdown, $screening->pass);

            $screening->update([
                ...$result,
                ...Usage::estimatedCost($screening->model, $result),
                'status' => 'screened',
                'status_message' => null,
            ]);
        } catch (Throwable $exception) {
            $screening->update(['status' => 'failed', 'status_message' => ErrorMessage::of($exception)]);

            return;
        }

        // 要確認 on the first pass: a second pass by the next model up.
        if ($screening->decision === 'review' && $screening->pass === 1) {
            self::queueFor($document, EditorialPolicy::nextModelUp($screening->model), 2);

            return;
        }

        // Adopted on a feed summary: fetch the full text.
        if ($document->refresh()->wantsFullText()) {
            FetchDocument::queueFor($document);

            return;
        }

        // Rejected with a short body: revise the document settings.
        if ($screening->decision === 'reject' && $document->refresh()->hasShortBody()) {
            $this->reviseSettings($document, $revise);
        }
    }

    /** Revise the source's document settings from this original and screen the grown documents again. */
    private function reviseSettings(Document $document, ReviseDocumentSettings $revise): void
    {
        // A failed revision is noted on the screening.
        try {
            $result = $revise($document->source, $document);
        } catch (Throwable $exception) {
            $this->screening->update(['status_message' => __('Short body; the document settings could not be revised: :reason', ['reason' => ErrorMessage::of($exception, 500)])]);

            return;
        }

        $this->screening->update(['status_message' => __('Short body; the document settings were revised (content: :content) and :grown documents screened again.', ['content' => $result['settings']['content'], 'grown' => count($result['grown'])])]);

        Document::query()->whereIn('id', $result['grown'])->get()->each(fn (Document $grown) => self::queueFor($grown));
    }
}
