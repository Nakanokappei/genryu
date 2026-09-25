<?php

use App\Actions\ScoreHeadline;
use App\Actions\ValidateArticle;
use App\Support\Hedges;

// Hedges are counted in the languages we write originals in; "can" / できる states what is possible, and is not one.
it('counts the hedges of a text', function (string $text, int $count) {
    expect(Hedges::count($text))->toBe($count);
})->with([
    ['Sensors could change how power lines are inspected, and may even replace climbers.', 2],
    ['Cement can take back the carbon dioxide it gave off.', 0],
    ['排出を減らせる可能性があり、普及するかもしれない。', 2],
    ['送電線の点検に、もう人は登らない', 0],
    ['Das könnte die Wartung vielleicht verändern.', 2],
    ['Cela pourrait changer le recyclage.', 1],
    ['这项技术或许会改变回收，也使分离成为可能。', 1],
]);

// A lead begins with the claim, not with the report of a study, and hedges at most once.
it('finds the problems of a lead', function () {
    expect(ValidateArticle::leadProblems('セメントは、固まるときに二酸化炭素を吸い戻せる。研究チームは強度を落とさずに8％を吸わせた。'))->toBe([])
        ->and(ValidateArticle::leadProblems('ある研究によると、この方法は排出を減らせる可能性がある。'))->toHaveCount(1)
        ->and(ValidateArticle::leadProblems('A new study shows the method could cut emissions and might spread.'))->toHaveCount(2);
});

// A hedged headline fails a must counted by code, like its length.
it('fails a hedged headline', function () {
    $review = ScoreHeadline::review(['musts' => array_fill_keys(array_keys(ScoreHeadline::MUSTS), true), 'common' => [], 'optional' => []], 'Sensors could change power line inspection');

    expect($review['musts_failed'])->toBe(['unhedged'])->and($review['passed'])->toBeFalse();
});
