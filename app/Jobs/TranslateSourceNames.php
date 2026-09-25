<?php

namespace App\Jobs;

use App\Enums\Language;
use App\Models\EditorialPolicy;
use App\Models\Source;
use App\OpenAi\Responses;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 名称 (UI "Names"): what a newly added source is called in each language,
 * proposed from its name in the UI language with the cheapest model. A name a
 * person has set is never replaced.
 */
class TranslateSourceNames implements ShouldQueue
{
    use Queueable;

    private const INSTRUCTION = 'You name the publisher of primary sources for a news media, in several languages. Given its name as written in one language, return what it is called in each language asked for: its official name in that language when it has one, else the established rendering, else a faithful translation. Keep an acronym in parentheses when the given name has one. Keep a series or catalogue name and its codes (such as "arXiv cs.AI") as they are and translate only the descriptive words.';

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public Source $source) {}

    /**
     * The languages still without a name.
     *
     * @return list<string>
     */
    public static function missing(Source $source): array
    {
        return array_values(array_diff(Language::codes(), array_keys($source->namesByLanguage())));
    }

    /** Ask for the missing names and keep the ones given. */
    public function handle(): void
    {
        $missing = self::missing($this->source);

        // Every language already has its name.
        if ($missing === []) {
            return;
        }

        $answer = Responses::send(self::request($this->source, $missing), timeout: 90, invalid: 'The agent did not return the names.')['json'];
        $names = array_filter(array_map(fn ($name): string => trim((string) $name), array_intersect_key($answer, array_flip($missing))), fn (string $name): bool => $name !== '');

        $this->source->update(['names' => [...$names, ...($this->source->names ?? [])]]);
    }

    /**
     * The request: the fixed instruction, then the name, one answer property per missing language.
     *
     * @param  list<string>  $languages
     * @return array<string, mixed>
     */
    public static function request(Source $source, array $languages): array
    {
        $asked = implode(', ', array_map(fn (string $code): string => Language::nameOf($code)." ({$code})", $languages));

        return [
            'model' => array_key_first(EditorialPolicy::TEXT_MODELS),
            'input' => [
                ['role' => 'developer', 'content' => self::INSTRUCTION],
                ['role' => 'user', 'content' => 'Name, as written in '.Language::nameOf(app()->getLocale()).": {$source->name}\nLanguages: {$asked}"],
            ],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'source_names', 'strict' => true, 'schema' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => $languages,
                'properties' => array_fill_keys($languages, ['type' => 'string']),
            ]]],
        ];
    }
}
