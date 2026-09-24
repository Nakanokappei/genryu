<?php

namespace App\Actions;

/**
 * 言語 (UI "Language") of a document, told from its script and commonest
 * words without a model; set right by a person on 文書.
 */
class DetectLanguage
{
    /** How many characters from the start are read. */
    private const SAMPLE_CHARS = 5000;

    /** Characters that differ between traditional and simplified Chinese, paired by position. */
    private const TRADITIONAL = '這個們來為國說時會對發學經開關與點於實體現產動區後進過還從電應術網機業質聯計際';

    private const SIMPLIFIED = '这个们来为国说时会对发学经开关与点于实体现产动区后进过还从电应术网机业质联计际';

    /** The commonest short words of the Latin-script languages, as whole words. */
    private const WORDS = [
        'en' => ['the', 'and', 'of', 'to', 'is', 'that', 'with', 'for', 'are', 'this'],
        'de' => ['der', 'die', 'und', 'das', 'ist', 'mit', 'von', 'für', 'nicht', 'ein'],
        'fr' => ['le', 'la', 'les', 'des', 'et', 'est', 'une', 'pour', 'dans', 'du'],
    ];

    /** The language of a text, as one of App\Enums\Language, or null when it has no letters to tell by. */
    public static function of(string $text): ?string
    {
        $sample = mb_substr($text, 0, self::SAMPLE_CHARS);
        $count = fn (string $pattern): int => (int) preg_match_all($pattern, $sample);

        // Kana: Japanese; Hangul: Korean; Han alone: Chinese.
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

        // No letters at all.
        if ($words === []) {
            return null;
        }

        $scores = array_map(fn (array $common): int => array_sum(array_map(fn (string $word): int => $words[$word] ?? 0, $common)), self::WORDS);
        arsort($scores);

        return reset($scores) > 0 ? (string) array_key_first($scores) : 'en';
    }
}
