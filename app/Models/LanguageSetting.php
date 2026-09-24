<?php

namespace App\Models;

use App\Enums\Language;
use Illuminate\Database\Eloquent\Model;

/**
 * 言語設定 (UI: "Language settings"), set at the top of 記事: for each
 * language we publish in, which primary sources get an article in it
 * (coverage: all = すべての一次情報の記事を用意する, own = その言語の一次情報のみ
 * 記事を用意する, none = 記事を作らない), and the additional prompt (言語別の
 * 追加プロンプト) every writer — headline, body and translation — is given
 * when it writes in that language: what belongs to the language, such as
 * だ・である調, rather than to the article. One row per language. The
 * prompts live in the database, never in the repository (see
 * EditorialPolicy::DEFAULTS).
 *
 * An article is written first in the language of its primary source and
 * translated from there, so the original is written whenever any
 * language wants the source, even one that does not publish the original
 * itself; it is then a working copy only.
 */
class LanguageSetting extends Model
{
    public const COVERAGES = ['all', 'own', 'none'];

    protected $fillable = ['language', 'coverage', 'prompt'];

    /** Which primary sources get an article in a language: all, own or none. */
    public static function coverage(string $language): string
    {
        return (string) (static::query()->where('language', $language)->value('coverage') ?? Language::tryFrom($language)?->defaultCoverage() ?? 'none');
    }

    /** The additional prompt for writing in a language, empty when there is none. */
    public static function prompt(?string $language): string
    {
        if ($language === null) {
            return '';
        }

        $row = static::query()->where('language', $language)->first();

        return trim((string) $row?->prompt);
    }

    /**
     * The additional prompt for a language as the message a writer is
     * given after the cached policy and before the fixed instruction —
     * the order the 記事 screen shows them in — or none when there is no
     * prompt for that language.
     *
     * @return list<array{role: string, content: string}>
     */
    public static function messages(?string $language): array
    {
        $prompt = self::prompt($language);

        return $prompt === '' ? [] : [['role' => 'developer', 'content' => 'Additional rules for writing in '.Language::nameOf($language).":\n".$prompt]];
    }

    /** Whether an article from a source in one language is published in another (or in its own). */
    public static function publishes(string $language, ?string $sourceLanguage): bool
    {
        return match (self::coverage($language)) {
            'all' => true,
            'own' => $language === $sourceLanguage,
            default => false,
        };
    }

    /**
     * The languages an article written in a source's language is
     * translated into: every other language that takes all sources.
     *
     * @return list<string>
     */
    public static function translationTargets(?string $sourceLanguage): array
    {
        return array_values(array_filter(Language::codes(), fn (string $language): bool => $language !== $sourceLanguage && self::coverage($language) === 'all'));
    }

    /** Whether an article is to be written at all for a source in this language: some language wants it. */
    public static function wantsArticle(?string $sourceLanguage): bool
    {
        return ($sourceLanguage !== null && self::publishes($sourceLanguage, $sourceLanguage)) || self::translationTargets($sourceLanguage) !== [];
    }
}
