<?php

namespace App\Actions;

use App\Jobs\TranslateSpotCheck;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\SpotCheck;
use Carbon\CarbonImmutable;

/**
 * 今日の抜き取りを作る (UI: "Draw today's spot check"): draw a day's
 * documents for a person to judge, from those the semantic filter
 * measured that day (their embedding was made that day, in the display
 * timezone), leaving out what the title filter excluded, what was
 * drawn before, and what a person already made an example of the
 * semantic filter (it has been judged, and its likeness is measured
 * without itself). The draw is stratified by where the likeness fell when
 * drawn — passed, just below the threshold, far below — so the line
 * itself is looked at; each row keeps its stratum's weight (how many
 * documents it stands for), so rates over the whole day can be
 * estimated without the bias of the strata. A day is drawn once.
 */
class DrawSpotCheck
{
    /**
     * @return array{drawn: int, population: int, already: bool}
     */
    public function __invoke(CarbonImmutable $day): array
    {
        if (SpotCheck::query()->whereDate('drawn_on', $day->toDateString())->exists()) {
            return ['drawn' => 0, 'population' => 0, 'already' => true];
        }

        $threshold = EditorialPolicy::likenessThreshold();
        $start = CarbonImmutable::parse($day->toDateString(), (string) config('app.display_timezone'))->startOfDay()->utc();

        $population = Document::query()->whereNull('excluded_by')->whereNotNull('likeness')->whereDoesntHave('spotCheck')->whereDoesntHave('semanticFilterExample')
            ->whereHas('embedding', fn ($query) => $query->where('created_at', '>=', $start)->where('created_at', '<', $start->addDay()))
            ->get(['id', 'likeness']);

        $strata = [
            'let_through' => $population->filter(fn (Document $document): bool => $document->likeness >= $threshold),
            'just_below' => $population->filter(fn (Document $document): bool => $document->likeness < $threshold && $document->likeness >= $threshold - SpotCheck::JUST_BELOW_WIDTH),
            'far_below' => $population->filter(fn (Document $document): bool => $document->likeness < $threshold - SpotCheck::JUST_BELOW_WIDTH),
        ];
        $drawn = 0;

        // Each stratum gives its share at random, or all it has; each drawn document stands for its stratum's size over the number drawn from it.
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
