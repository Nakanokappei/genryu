<?php

namespace App\Jobs;

use App\Actions\ProposeArticle;
use App\Models\Article;
use App\Models\EditorialPolicy;
use App\Models\Material;
use App\Models\Prompt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * 記事を生成 (UI: "Generate article", stage 2.4 of docs/HANDOVER.md): an
 * article is made in three steps, each in the background. The headline
 * comes first, from the material (App\Jobs\RefineHeadline); this job then
 * has the agent write the body under that headline per the article
 * generation layer of the editorial policy, checks that a body came back,
 * and keeps it; the translations follow. The article pins the prompt
 * versions and the models it was written with and keeps the usage of the
 * body's call, as a screening and a material do. The outcome lands on the
 * article (status 生成中 / 下書き / 失敗) so the screens can show it.
 *
 * The length is counted here, not trusted to the model (added
 * 2026-09-23, when bodies of 1,300 characters came back against a limit
 * of 1,200): a body outside LENGTHS is written once more with its count
 * in hand, and the one nearer the range is kept. Nothing waits for a
 * person, so a body still outside it is kept and its count shown.
 */
class GenerateArticle implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** How long a body may be, not counting its sources: characters in Chinese or Japanese, words otherwise. */
    public const LENGTHS = ['characters' => [800, 1200], 'words' => [500, 800]];

    public function __construct(public Article $article) {}

    /**
     * Queue the writing of a material's article: the row appears at once
     * as 生成中, whether it is new or being written again, and the
     * headline is written first; this job follows it. This is the
     * original — the one written from the material, in the language of
     * the primary source — so it is the material's article that has no
     * article behind it; the translations are queued once it is written.
     */
    public static function queueFor(Material $material): Article
    {
        $article = Article::query()->updateOrCreate(
            ['material_id' => $material->id, 'translated_from_id' => null],
            [
                'status' => 'generating',
                'status_message' => null,
                'prompt_id' => Prompt::current('article', EditorialPolicy::bodyFor('article'))->id,
                'model' => EditorialPolicy::modelFor('article'),
            ],
        );

        return RefineHeadline::queueFor($article);
    }

    public function handle(ProposeArticle $propose): void
    {
        $article = $this->article;
        $material = $article->material;

        try {
            if ($material === null || $material->status !== 'extracted' || $material->data === null) {
                throw new RuntimeException(__('The material has not been extracted yet.'));
            }

            if (trim((string) $article->title) === '') {
                throw new RuntimeException(__('The headline has not been written yet.'));
            }

            // The policy read is the version pinned when the job was queued, not whatever the screen holds by now.
            $policy = $article->prompt !== null ? $article->prompt->text : '';

            if (trim($policy) === '') {
                throw new RuntimeException(__('The article generation layer of the editorial policy is empty.'));
            }

            $document = $material->document;
            $model = (string) $article->model;
            $write = fn (?array $revision = null): array => $propose($policy, $model, (array) $material->data, (string) $article->title, $document->title, $document->url, $revision);
            $result = $write();
            $body = trim((string) ($result['json']['body'] ?? ''));

            if ($body === '') {
                throw new RuntimeException(__('The agent did not return a body.'));
            }

            // A body outside the range is written once more with its count in hand; the one nearer the range is kept.
            $length = self::lengthOf($body, $result['json']['language'] ?? null);

            if ($length['off'] !== 0) {
                $problem = "It is {$length['count']} {$length['unit']} long, not counting the sources; it must be {$length['min']}–{$length['max']} {$length['unit']}.";
                $retry = $write(['body' => $body, 'problem' => $problem]);
                $retryBody = trim((string) ($retry['json']['body'] ?? ''));
                $retryLength = self::lengthOf($retryBody, $retry['json']['language'] ?? null);
                // Both calls are paid for, so both are counted.
                $usage = array_map(fn ($first, $second) => $first === null && $second === null ? null : (int) $first + (int) $second, $result['usage'], $retry['usage']);
                $result = $retryBody !== '' && abs($retryLength['off']) < abs($length['off']) ? $retry : $result;
                $result['usage'] = array_combine(array_keys($retry['usage']), $usage);
                $body = trim((string) $result['json']['body']);
                $length = self::lengthOf($body, $result['json']['language'] ?? null);
            }

            $article->update([
                'body' => Article::separateBlocks($body),
                // The language the agent says it wrote in, which is the material's and so the primary source's.
                'language' => in_array($result['json']['language'] ?? null, Article::SOURCE_LANGUAGES, true) ? $result['json']['language'] : null,
                'status' => 'draft',
                'status_message' => __('Generated by :model.', ['model' => $model]).($length['off'] === 0 ? '' : ' '.__('The body is :count :unit, outside :min–:max.', ['count' => $length['count'], 'unit' => __($length['unit']), 'min' => $length['min'], 'max' => $length['max']])),
                ...$result['usage'],
                'estimated_total_cost' => ScreenDocument::estimatedCost($model, $result['usage'])['estimated_total_cost'],
            ]);
        } catch (Throwable $exception) {
            $article->update(['status' => 'failed', 'status_message' => mb_substr($exception->getMessage(), 0, 1000)]);

            return;
        }

        // Nothing waits for a person: the languages we publish in follow the written article.
        foreach ($article->refresh()->translationLanguages() as $language) {
            TranslateArticle::queueFor($article, $language);
        }
    }

    /**
     * The length of a body as the policy counts it: the sources section
     * (the heading that names 出典 or Sources, and what follows) and the
     * Markdown marks left out; characters without spaces in Chinese or
     * Japanese (by the language the agent named, else by the script),
     * words otherwise. `off` is how far outside the range it
     * is, negative when short, 0 when inside.
     *
     * @return array{count: int, unit: string, min: int, max: int, off: int}
     */
    public static function lengthOf(string $body, ?string $language): array
    {
        $text = preg_replace('/^#{1,6}\s*(出典|出处|出處|Sources?|Quellen)\b.*\z/imsu', '', $body) ?? $body;
        $text = preg_replace('/^#{1,6}\s*|\[([^\]]*)\]\([^)]*\)|[*_`>]/mu', '$1', $text) ?? $text;
        $isCjk = $language === null ? preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $text) === 1 : in_array($language, ['ja', 'zh-Hans', 'zh-Hant'], true);
        $unit = $isCjk ? 'characters' : 'words';
        $count = $unit === 'characters'
            ? mb_strlen(preg_replace('/\s+/u', '', $text) ?? $text)
            : count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        [$min, $max] = self::LENGTHS[$unit];

        return ['count' => $count, 'unit' => $unit, 'min' => $min, 'max' => $max, 'off' => $count < $min ? $count - $min : max(0, $count - $max)];
    }
}
