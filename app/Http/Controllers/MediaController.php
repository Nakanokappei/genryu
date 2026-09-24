<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * メディアサイト (UI: "Media site", sidebar group プレビュー): the media made
 * with Genryu as a reader would see it — Technology Watch, one front page
 * and one page per article in each language we publish in — to check how
 * the articles look and to show what kind of site the system makes. It
 * is a preview, not the publication: nothing is published yet, so an
 * article is shown as if out at its scheduled local time (or when it was
 * written, if not scheduled), and only the last WINDOW_DAYS days are
 * shown. Open to anyone, without signing in.
 */
class MediaController extends Controller
{
    /** How many days back the site reaches; the top images are deleted after as many (media:prune-images). */
    public const WINDOW_DAYS = 30;

    /** The language of the front page at /media. */
    public const DEFAULT_LANGUAGE = 'ja';

    /** What the site says around the articles, in each language. */
    public const TEXT = [
        'ja' => ['tagline' => '研究室から産業へ。新しい技術の道筋を追う', 'latest' => '最新の記事', 'scheduled' => '公開予定', 'image' => 'AI が描いたイメージ', 'disclosure' => '記事は一次情報をもとに AI が執筆・翻訳し、トップ画像は AI が描いたイメージです。記事中の図版は一次情報からの引用です。', 'demo' => 'このサイトは Genryu で作ったメディアのデモです（直近30日分）。', 'empty' => 'まだ記事がありません。', 'back' => 'トップへ'],
        'en' => ['tagline' => 'From the laboratory to industry: following new technology on its way', 'latest' => 'Latest', 'scheduled' => 'Scheduled', 'image' => 'Image drawn by AI', 'disclosure' => 'Articles are written and translated by AI from primary sources; top images are drawn by AI. Figures in articles are quoted from the primary sources.', 'demo' => 'This site is a demo of a media made with Genryu (the last 30 days).', 'empty' => 'No articles yet.', 'back' => 'Front page'],
        'zh-Hant' => ['tagline' => '從實驗室到產業，追蹤新技術的路徑', 'latest' => '最新文章', 'scheduled' => '預定發布', 'image' => 'AI 繪製的示意圖', 'disclosure' => '文章由 AI 根據第一手資料撰寫與翻譯，首圖為 AI 繪製的示意圖。文中圖表引用自第一手資料。', 'demo' => '本站為以 Genryu 製作的媒體示範（最近 30 天）。', 'empty' => '尚無文章。', 'back' => '回首頁'],
        'zh-Hans' => ['tagline' => '从实验室到产业，追踪新技术的路径', 'latest' => '最新文章', 'scheduled' => '预定发布', 'image' => 'AI 绘制的示意图', 'disclosure' => '文章由 AI 根据第一手资料撰写和翻译，首图为 AI 绘制的示意图。文中图表引用自第一手资料。', 'demo' => '本站为用 Genryu 制作的媒体演示（最近 30 天）。', 'empty' => '暂无文章。', 'back' => '返回首页'],
        'de' => ['tagline' => 'Vom Labor in die Industrie: neue Technologien auf ihrem Weg', 'latest' => 'Neueste Artikel', 'scheduled' => 'Geplant', 'image' => 'Von KI gezeichnetes Bild', 'disclosure' => 'Die Artikel werden von KI aus Primärquellen geschrieben und übersetzt; die Titelbilder zeichnet eine KI. Abbildungen in den Artikeln sind Zitate aus den Primärquellen.', 'demo' => 'Diese Seite ist eine Demo eines mit Genryu erstellten Mediums (die letzten 30 Tage).', 'empty' => 'Noch keine Artikel.', 'back' => 'Startseite'],
        'ko' => ['tagline' => '연구실에서 산업으로, 새로운 기술의 길을 따라가다', 'latest' => '최신 기사', 'scheduled' => '공개 예정', 'image' => 'AI가 그린 이미지', 'disclosure' => '기사는 1차 자료를 바탕으로 AI가 작성·번역하며, 대표 이미지는 AI가 그린 이미지입니다. 기사 속 도판은 1차 자료에서 인용한 것입니다.', 'demo' => '이 사이트는 Genryu로 만든 미디어의 데모입니다(최근 30일).', 'empty' => '아직 기사가 없습니다.', 'back' => '첫 페이지'],
        'fr' => ['tagline' => 'Du laboratoire à l’industrie : suivre les nouvelles technologies', 'latest' => 'Derniers articles', 'scheduled' => 'Programmé', 'image' => 'Image dessinée par une IA', 'disclosure' => 'Les articles sont rédigés et traduits par une IA à partir de sources primaires ; les images d’en-tête sont dessinées par une IA. Les figures des articles sont citées des sources primaires.', 'demo' => 'Ce site est une démo d’un média réalisé avec Genryu (les 30 derniers jours).', 'empty' => 'Pas encore d’articles.', 'back' => 'Accueil'],
    ];

    /** The front page of a language: the newest article large, the rest after it. */
    public function index(?string $language = null): View
    {
        $language ??= self::DEFAULT_LANGUAGE;

        return view('media.index', ['language' => $language, 'articles' => self::articles($language), 'languages' => self::languages()]);
    }

    /** One article, in the language it is written or translated in. */
    public function show(string $language, Article $article): View
    {
        abort_unless($article->language === $language && self::isShown($article), 404);

        return view('media.show', ['language' => $language, 'article' => $article, 'languages' => self::languages()]);
    }

    /**
     * An article's top image, which is ours (drawn by the image model), so
     * the site serves it; every language version shows its original's.
     */
    public function image(Article $article): StreamedResponse
    {
        $path = self::imagePathOf($article);
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'public, max-age=86400']);
    }

    /**
     * The articles of a language the site shows, newest first: a body
     * written, the language version to be published (言語設定), and its
     * date within the window.
     *
     * @return Collection<int, Article>
     */
    public static function articles(string $language): Collection
    {
        return Article::query()->where('language', $language)->whereNotNull('body')->with('material.document.source', 'translatedFrom')->get()
            ->filter(fn (Article $article): bool => self::isShown($article))
            ->sortByDesc(fn (Article $article): int => self::dateOf($article)->getTimestamp())
            ->values();
    }

    /** Whether the site shows an article. */
    public static function isShown(Article $article): bool
    {
        return $article->body !== null && $article->isPublishable() && self::dateOf($article)->greaterThanOrEqualTo(now()->subDays(self::WINDOW_DAYS));
    }

    /** When the article counts as out: its scheduled local time, or, not scheduled, when it was written, in its language's zone. */
    public static function dateOf(Article $article): CarbonImmutable
    {
        return $article->scheduledLocal() ?? CarbonImmutable::parse($article->created_at)->setTimezone($article->timezone());
    }

    /** Whether the article's time has not come yet. */
    public static function isUpcoming(Article $article): bool
    {
        return self::dateOf($article)->isFuture();
    }

    /** The lead as plain text: the one above the separator line, or, without one, the body's first paragraph that is not a heading. */
    public static function lead(Article $article): string
    {
        [$lead, $body] = $article->leadAndBody();
        $paragraphs = preg_split('/\n\s*\n/', $lead ?? $body) ?: [];
        $first = collect($paragraphs)->first(fn (string $paragraph): bool => ! str_starts_with(ltrim($paragraph), '#')) ?? '';

        return trim(strip_tags(Str::markdown($first)));
    }

    /** The top image's path on the local disk: the original's, for a translation too. */
    public static function imagePathOf(Article $article): ?string
    {
        return ($article->isOriginal() ? $article : $article->translatedFrom)?->image_path;
    }

    /**
     * The languages that have an article to show, in the order we publish them in.
     *
     * @return list<string>
     */
    public static function languages(): array
    {
        return array_values(array_filter(Article::LANGUAGES, fn (string $language): bool => self::articles($language)->isNotEmpty()));
    }
}
