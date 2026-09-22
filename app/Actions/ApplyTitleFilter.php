<?php

namespace App\Actions;

use App\Models\Document;
use App\Models\EditorialPolicy;

/**
 * Apply the title filter (UI: 編集方針 — タイトルフィルタ) to every document
 * already listed: the rules are deterministic and cheap, so a change to
 * them need not wait for the next update list. A title that matches a
 * rule is marked 対象外 (excluded_by = the rule) whatever was fetched for
 * it; one that no longer matches any rule has its mark removed and, not
 * fetched yet, waits for 文書を取得 like any other.
 */
class ApplyTitleFilter
{
    /**
     * @return array{excluded: int, restored: int} how many documents were newly marked, and how many marks were removed
     */
    public function __invoke(): array
    {
        $rules = EditorialPolicy::excludeKeywords();
        $excluded = 0;
        $restored = 0;

        Document::query()->select(['id', 'title', 'excluded_by'])->chunkById(500, function ($documents) use ($rules, &$excluded, &$restored): void {
            foreach ($documents as $document) {
                $rule = EditorialPolicy::excludedBy($document->title, $rules);

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
