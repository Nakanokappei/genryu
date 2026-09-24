<?php

namespace App\Actions;

use App\Jobs\TranslateSpotCheck;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\SpotCheck;
use Carbon\CarbonImmutable;

/**
 * 今日の抜き取りを作る (UI "Draw today's spot check"): draws the day's
 * documents the semantic filter measured, stratified by likeness against
 * the threshold, each row weighted by its stratum. A day is drawn once.
 * Excluded documents, earlier draws and semantic filter examples are left out.
 */
class DrawSpotCheck
{
    /**
     * Draws the given day (display timezone).
     *
     * @return array{drawn: int, population: int, already: bool}
     */
    public function __invoke(CarbonImmutable $day): array
    {
        // Already drawn.
        if (SpotCheck::query()->whereDate('drawn_on', $day->toDateString())->exists()) {
            return ['drawn' => 0, 'population' => 0, 'already' => true];
        }

        $threshold = EditorialPolicy::likenessThreshold();
        $start = CarbonImmutable::parse($day->toDateString(), (string) config('app.display_timezone'))->startOfDay()->utc();

        $population = Document::query()->whereNull('excluded_by')->whereNotNull('likeness')->whereDoesntHave('spotCheck')->whereDoesntHave('semanticFilterExample')
            ->whereHas('embedding', fn ($query) => $query->where('created_at', '>=', $start)->where('created_at', '<', $start->addDay()))
            ->get(['id', 'likeness']);

        // Documents by stratum.
        $strata = [
            'let_through' => $population->filter(fn (Document $document): bool => $document->likeness >= $threshold),
            'just_below' => $population->filter(fn (Document $document): bool => $document->likeness < $threshold && $document->likeness >= $threshold - SpotCheck::JUST_BELOW_WIDTH),
            'far_below' => $population->filter(fn (Document $document): bool => $document->likeness < $threshold - SpotCheck::JUST_BELOW_WIDTH),
        ];
        $drawn = 0;

        // Random picks per stratum; weight = stratum size / number picked.
        foreach ($strata as $stratum => $documents) {
            $picked = $documents->shuffle()->take(SpotCheck::STRATA[$stratum]);

            foreach ($picked as $document) {
                $check = SpotCheck::query()->create([
                    'document_id' => $document->id, 'drawn_on' => $day->toDateString(), 'stratum' => $stratum,
                    'weight' => $documents->count() / $picked->count(), 'likeness' => $document->likeness,
                    'threshold' => $threshold, 'let_through' => $document->likeness >= $threshold,
                ]);
                TranslateSpotCheck::dispatch($check);
                $drawn++;
            }
        }

        return ['drawn' => $drawn, 'population' => $population->count(), 'already' => false];
    }
}
