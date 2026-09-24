<?php

namespace App\Actions;

use App\Models\Document;
use App\Models\EditorialPolicy;

/**
 * Applies the タイトルフィルタ (UI "Title filter", set on 情報源) to every
 * listed document: a match is marked 対象外 (excluded_by = the rule), a
 * title no longer matching has its mark removed.
 */
class ApplyTitleFilter
{
    /**
     * @return array{excluded: int, restored: int} newly marked, marks removed
     */
    public function __invoke(): array
    {
        $rules = EditorialPolicy::excludeKeywords();
        $excluded = 0;
        $restored = 0;

        Document::query()->select(['id', 'title', 'excluded_by'])->chunkById(500, function ($documents) use ($rules, &$excluded, &$restored): void {
            foreach ($documents as $document) {
                $rule = EditorialPolicy::excludedBy($document->title, $rules);

                // Unchanged: nothing to write.
                if ($rule === $document->excluded_by) {
                    continue;
                }

                Document::query()->whereKey($document->id)->update(['excluded_by' => $rule]);
                $rule === null ? $restored++ : $excluded++;
            }
        });

        return ['excluded' => $excluded, 'restored' => $restored];
    }
}
