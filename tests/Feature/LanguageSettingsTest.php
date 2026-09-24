<?php

use App\Actions\DetectLanguage;
use App\Actions\ProposeArticle;
use App\Actions\ProposeTranslation;
use App\Actions\ScheduleArticles;
use App\Enums\Language;
use App\Jobs\RefineHeadline;
use App\Models\Article;
use App\Models\Document;
use App\Models\LanguageSetting;
use App\Models\Material;
use App\Models\Prompt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

// The language of a source is told from its text without a model, and a person can set it right on the documents screen.
it('tells the language of a document from its text', function () {
    expect(DetectLanguage::of('ナフサ分解炉の熱の85％をアンモニアに替えた。'))->toBe('ja')
        ->and(DetectLanguage::of('양자 컴퓨터의 제어 부품을 개발한다. 연구를 시작했다.'))->toBe('ko')
        ->and(DetectLanguage::of('這個研究團隊開發了新的電池技術，對產業發展很重要。'))->toBe('zh-Hant')
        ->and(DetectLanguage::of('这个研究团队开发了新的电池技术，对产业发展很重要。'))->toBe('zh-Hans')
        ->and(DetectLanguage::of('The team reports that the membrane is twice as fast and that it is cheap.'))->toBe('en')
        ->and(DetectLanguage::of('Die Forscher zeigen, dass der Speicher mit der neuen Methode und ohne Kobalt funktioniert.'))->toBe('de')
        ->and(DetectLanguage::of('Les chercheurs montrent que la couche est plus fine et que le procédé est une avancée pour les batteries.'))->toBe('fr');

    $document = Document::factory()->fetched()->create(['title' => 'Nouvelle batterie', 'markdown' => 'Les chercheurs du CNRS et des universités présentent une nouvelle batterie pour les voitures.']);
    expect($document->language)->toBe('fr');

    Livewire::test('pages::editorial.documents.index')->call('setLanguage', $document->id, 'de');
    expect($document->refresh()->language)->toBe('de');

    // Set once, the language is not guessed again when the text changes.
    $document->update(['markdown' => 'The researchers present a new battery for the cars of the future.']);
    expect($document->refresh()->language)->toBe('de');
});

// Which sources get an article in each language: all of them, only those in the language, or none; the original is written whenever some language wants it.
it('decides the languages an article is published and translated in', function () {
    expect(LanguageSetting::translationTargets('ja'))->toBe(['en', 'zh-Hant', 'zh-Hans'])
        ->and(LanguageSetting::translationTargets('fr'))->toBe(['en', 'zh-Hant', 'ja', 'zh-Hans'])
        ->and(LanguageSetting::publishes('fr', 'fr'))->toBeTrue()
        ->and(LanguageSetting::publishes('fr', 'ja'))->toBeFalse();

    Livewire::test('pages::editorial.articles.index')
        ->set('coverages.fr', 'none')->set('coverages.zh-Hant', 'own')->set('coverages.ko', 'all')
        ->call('saveLanguages')->assertHasNoErrors();

    expect(LanguageSetting::translationTargets('fr'))->toBe(['en', 'ja', 'ko', 'zh-Hans'])
        ->and(LanguageSetting::publishes('fr', 'fr'))->toBeFalse()
        // A French source is still written in French, as the copy its translations are made from.
        ->and(LanguageSetting::wantsArticle('fr'))->toBeTrue();

    Livewire::test('pages::editorial.articles.index')->set('coverages.en', 'nowhere')->call('saveLanguages')->assertHasErrors(['coverages.en']);
});

// A source no language wants gets no article from the bulk generation.
it('writes no article for a source no language wants', function () {
    foreach (Language::codes() as $language) {
        LanguageSetting::query()->create(['language' => $language, 'coverage' => $language === 'ja' ? 'all' : 'own']);
    }

    $german = Material::factory()->create();
    $german->document->update(['language' => 'de']);
    $english = Material::factory()->create();
    $english->document->update(['language' => 'en']);
    LanguageSetting::query()->where('language', 'de')->update(['coverage' => 'none']);
    LanguageSetting::query()->where('language', 'ja')->update(['coverage' => 'own']);

    Livewire::test('pages::editorial.articles.index')->call('generate');

    // English takes its own sources; German takes none and nobody takes every source.
    Queue::assertPushed(RefineHeadline::class, 1);
    expect($english->articles()->count())->toBe(1)->and($german->articles()->count())->toBe(0);
});

// The additional prompt of a language is shared by the headline, the body and the translation, sent between the policy and the fixed instruction.
it('gives every writer the additional prompt of the language it writes in', function () {
    // A placeholder: the real prompts are assets and live in the database, not in the repository.
    LanguageSetting::query()->create(['language' => 'ja', 'coverage' => 'all', 'additional_prompt' => '日本語の追加ルール（テスト用）。']);

    Livewire::test('pages::editorial.articles.index')
        ->assertSet('languagePrompts.ja', '日本語の追加ルール（テスト用）。')
        ->set('promptLanguage', 'ko')->set('languagePrompts.ko', '합니다체로 쓴다.')
        ->call('savePolicy')->assertHasNoErrors();

    $body = ProposeArticle::request('policy', 'gpt-5.6-luna', ['angle' => 'x'], '見出し', '文書', 'https://example.jp', null, 'ja');
    expect(array_column($body['input'], 'role'))->toBe(['developer', 'developer', 'developer', 'user'])
        ->and($body['input'][1]['content'])->toContain('日本語')->toContain('日本語の追加ルール（テスト用）。')
        ->and($body['input'][2]['content'])->toBe(ProposeArticle::INSTRUCTIONS);

    // English has no additional prompt, so nothing is added.
    expect(ProposeArticle::request('policy', 'gpt-5.6-luna', [], 'Headline', 'Doc', 'https://example.com', null, 'en')['input'])->toHaveCount(3);

    $article = Article::factory()->create(['language' => 'ja']);
    expect(ProposeTranslation::request('policy', 'gpt-5.6-luna', $article, 'ko', [], 'Doc', 'https://example.com')['input'][1]['content'])->toContain('합니다체로 쓴다.');
});

// A translation whose language no longer publishes the source gets no time; the original holds the slot as the anchor.
it('schedules only the language versions that are published', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 00:00:00', 'UTC'));
    $original = Article::factory()->create(['language' => 'ja']);
    $original->material->document->update(['published_at' => now()->subDay(), 'language' => 'ja']);
    $original->qualityChecks()->create(['prompt_id' => Prompt::current('quality', 'rubric')->id, 'model' => 'gpt-5.6-luna', 'status' => 'checked', 'score' => 80]);
    $english = Article::factory()->for($original->material)->create(['translated_from_id' => $original->id, 'language' => 'en']);
    $german = Article::factory()->for($original->material)->create(['translated_from_id' => $original->id, 'language' => 'de']);

    app(ScheduleArticles::class)(CarbonImmutable::now());

    expect($original->refresh()->scheduled_at)->not->toBeNull()
        ->and($english->refresh()->scheduled_at)->not->toBeNull()
        // German takes only German sources.
        ->and($german->refresh()->scheduled_at)->toBeNull()
        ->and($german->isPublishable())->toBeFalse();
});
