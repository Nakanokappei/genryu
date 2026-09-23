<?php

namespace App\Actions;

/**
 * 言語 (UI: "Language") of a primary source, told from its text without a
 * model: kana make it Japanese, Hangul Korean, Han alone Chinese — written
 * in traditional or simplified characters by which of the two it uses
 * more of — and a Latin text English, German or French by the words that
 * are commonest in each. A guess, made once when the document is read;
 * the documents screen lets a person set it right.
 */
class DetectLanguage
{
    /** How much of a document is read: its opening says what language it is in. */
    private const SAMPLE_CHARS = 5000;

    /** Characters written one way in traditional Chinese and another in simplified, each side of a pair. */
    private const TRADITIONAL = '這個們來為國說時會對發學經開關與點於實體現產動區後進過還從電應術網機業質聯計際';

    private const SIMPLIFIED = '这个们来为国说时会对发学经开关与点于实体现产动区后进过还从电应术网机业质联计际';

    /** The commonest short words of the Latin-script languages, as whole words. */
    private const WORDS = [
        'en' => ['the', 'and', 'of', 'to', 'is', 'that', 'with', 'for', 'are', 'this'],
        'de' => ['der', 'die', 'und', 'das', 'ist', 'mit', 'von', 'für', 'nicht', 'ein'],
        'fr' => ['le', 'la', 'les', 'des', 'et', 'est', 'une', 'pour', 'dans', 'du'],
    ];

    /** The language of a text, as one of Article::LANGUAGES, or null when it has no letters to tell by. */
    public static function of(string $text): ?string
    {
        $sample = mb_substr($text, 0, self::SAMPLE_CHARS);
        $count = fn (string $pattern): int => (int) preg_match_all($pattern, $sample);

        // Kana are Japanese only; Hangul Korean only; Han without either is Chinese.
        if ($count('/[\p{Hiragana}\p{Katakana}]/u') >= 5) {
            return 'ja';
        }

        if ($count('/\p{Hangul}/u') >= 5) {
            return 'ko';
        }

        if ($count('/\p{Han}/u') >= 5) {
            $traditional = $count('/['.self::TRADITIONAL.']/u');
            $simplified = $count('/['.self::SIMPLIFIED.']/u');

            return $traditional > $simplified ? 'zh-Hant' : 'zh-Hans';
        }

        // A Latin text by its commonest words; English when nothing tells them apart.
        $words = array_count_values(preg_split('/[^\p{L}]+/u', mb_strtolower($sample), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        if ($words === []) {
            return null;
        }

        $scores = array_map(fn (array $common): int => array_sum(array_map(fn (string $word): int => $words[$word] ?? 0, $common)), self::WORDS);
        arsort($scores);

        return reset($scores) > 0 ? (string) array_key_first($scores) : 'en';
    }
}
