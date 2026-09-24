<?php

namespace App\Models;

use App\Enums\Language;
use Illuminate\Database\Eloquent\Model;

/**
 * 言語設定 (UI "Language settings"), one row per language: its coverage
 * (all / own / none, UI すべての一次情報の記事を用意する / その言語の一次情報のみ
 * 記事を用意する / 記事を作らない) and 言語別の追加プロンプト (additional_prompt).
 */
class LanguageSetting extends Model
{
    public const COVERAGES = ['all', 'own', 'none'];

    protected $fillable = ['language', 'coverage', 'additional_prompt'];

    /** Which primary sources get an article in a language: all, own or none. */
    public static function coverage(string $language): string
    {
        return (string) (static::query()->where('language', $language)->value('coverage') ?? Language::tryFrom($language)?->defaultCoverage() ?? 'none');
    }

    /** A language's additional prompt, or ''. */
    public static function additionalPrompt(?string $language): string
    {
        // No language, no prompt.
        if ($language === null) {
            return '';
        }

        $row = static::query()->where('language', $language)->first();

        return trim((string) $row?->additional_prompt);
    }

    /**
     * The additional prompt as a developer message (sent between the cached policy and the instruction).
     *
     * @return list<array{role: string, content: string}>
     */
    public static function messages(?string $language): array
    {
        $prompt = self::additionalPrompt($language);

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
     * The languages to translate into: every other language whose coverage is all.
     *
     * @return list<string>
     */
    public static function translationTargets(?string $sourceLanguage): array
    {
        return array_values(array_filter(Language::codes(), fn (string $language): bool => $language !== $sourceLanguage && self::coverage($language) === 'all'));
    }

    /** Whether any language wants an article from a source in this language. */
    public static function wantsArticle(?string $sourceLanguage): bool
    {
        return ($sourceLanguage !== null && self::publishes($sourceLanguage, $sourceLanguage)) || self::translationTargets($sourceLanguage) !== [];
    }
}
