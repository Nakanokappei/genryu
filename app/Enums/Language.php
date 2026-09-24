<?php

namespace App\Enums;

/** The languages we publish in, in display order. */
enum Language: string
{
    case English = 'en';
    case TraditionalChinese = 'zh-Hant';
    case Japanese = 'ja';
    case German = 'de';
    case Korean = 'ko';
    case French = 'fr';
    case SimplifiedChinese = 'zh-Hans';

    /**
     * Every language code, in order.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(fn (self $language): string => $language->value, self::cases());
    }

    /**
     * Every language code with its display name, in order.
     *
     * @return array<string, string>
     */
    public static function names(): array
    {
        return array_combine(self::codes(), array_map(fn (self $language): string => $language->label(), self::cases()));
    }

    /** A code's display name, or the code itself when unknown. */
    public static function nameOf(?string $code): string
    {
        return self::tryFrom((string) $code)?->label() ?? (string) $code;
    }

    /** The display name, in the language itself. */
    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::TraditionalChinese => '繁體中文',
            self::Japanese => '日本語',
            self::German => 'Deutsch',
            self::Korean => '한국어',
            self::French => 'Français',
            self::SimplifiedChinese => '简体中文',
        };
    }

    /** The timezone a language version is published in. */
    public function timezone(): string
    {
        return match ($this) {
            self::English => 'America/New_York',
            self::TraditionalChinese => 'Asia/Taipei',
            self::Japanese => 'Asia/Tokyo',
            self::German => 'Europe/Berlin',
            self::Korean => 'Asia/Seoul',
            self::French => 'Europe/Paris',
            self::SimplifiedChinese => 'Asia/Shanghai',
        };
    }

    /** The 出典 label before a quoted figure's source. */
    public function sourceLabel(): string
    {
        return match ($this) {
            self::English, self::French => 'Source',
            self::TraditionalChinese => '出處',
            self::Japanese => '出典',
            self::German => 'Quelle',
            self::Korean => '출처',
            self::SimplifiedChinese => '出处',
        };
    }

    /** The coverage (LanguageSetting::COVERAGES) until 言語設定 is saved. */
    public function defaultCoverage(): string
    {
        return match ($this) {
            self::English, self::TraditionalChinese, self::Japanese, self::SimplifiedChinese => 'all',
            self::German, self::Korean, self::French => 'own',
        };
    }

    /** Whether length is counted in characters rather than words. */
    public function countsCharacters(): bool
    {
        return in_array($this, [self::TraditionalChinese, self::Japanese, self::Korean, self::SimplifiedChinese], true);
    }
}
