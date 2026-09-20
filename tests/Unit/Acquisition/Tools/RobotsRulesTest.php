<?php

use App\Acquisition\Tools\Http\RobotsRules;

$robots = <<<'TXT'
    # Example robots.txt
    User-agent: *
    Disallow: /private/
    Allow: /private/public/
    Disallow: /*.json$

    User-agent: TechnologyWatch
    User-agent: OtherBot
    Disallow: /files/*.pdf$
    Disallow: /drafts

    User-agent: EmptyBot
    Disallow:
    TXT;

it('applies the wildcard group when no group names our token', function () use ($robots) {
    $rules = RobotsRules::parse($robots);

    expect($rules->allows('/private/x', 'SomeCrawler'))->toBeFalse()
        ->and($rules->allows('/private/public/y', 'SomeCrawler'))->toBeTrue()
        ->and($rules->allows('/api/data.json', 'SomeCrawler'))->toBeFalse()
        ->and($rules->allows('/api/data.json?x=1', 'SomeCrawler'))->toBeTrue()
        ->and($rules->allows('/news/', 'SomeCrawler'))->toBeTrue();
});

it('prefers the group that names our token, matched case-insensitively as a substring', function () use ($robots) {
    $rules = RobotsRules::parse($robots);

    expect($rules->allows('/files/report.pdf', 'technologywatch/0.1'))->toBeFalse()
        ->and($rules->allows('/files/report.pdf?dl=1', 'TechnologyWatch/0.1'))->toBeTrue()
        ->and($rules->allows('/drafts/2026', 'TechnologyWatch/0.1'))->toBeFalse()
        // Our group has no rule for /private/, and groups do not inherit from "*".
        ->and($rules->allows('/private/x', 'TechnologyWatch/0.1'))->toBeTrue();
});

it('treats an empty Disallow as allow-all and an absent file as allow-all', function () use ($robots) {
    expect(RobotsRules::parse($robots)->allows('/anything', 'EmptyBot'))->toBeTrue()
        ->and(RobotsRules::parse('')->allows('/anything', 'TechnologyWatch'))->toBeTrue();
});

it('lets the longest matching rule win, with Allow winning ties', function () {
    $rules = RobotsRules::parse("User-agent: *\nDisallow: /a\nAllow: /a/b\nDisallow: /a/b/c\nAllow: /x\nDisallow: /x");

    expect($rules->allows('/a', '*'))->toBeFalse()
        ->and($rules->allows('/a/b', '*'))->toBeTrue()
        ->and($rules->allows('/a/b/c/d', '*'))->toBeFalse()
        ->and($rules->allows('/x', '*'))->toBeTrue();
});
