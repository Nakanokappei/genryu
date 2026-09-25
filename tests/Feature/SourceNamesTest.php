<?php

use App\Actions\ProposeTranslation;
use App\Jobs\TranslateSourceNames;
use App\Models\Article;
use App\Models\Material;
use App\Models\Source;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

// 名称 on the source's detail: one field per language; the UI language's is the source's name; an empty one is not kept.
it('saves what a source is called in each language', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $source = Source::factory()->create(['name' => '国防先端研究計画局（DARPA）']);

    Livewire::test('pages::editorial.sources.show', ['source' => $source])
        ->assertSee('上の名称')
        ->set('names.en', ' Defense Advanced Research Projects Agency (DARPA) ')
        ->set('names.fr', '')
        ->call('saveNames')->assertHasNoErrors();

    $source->refresh();
    expect($source->names)->toBe(['en' => 'Defense Advanced Research Projects Agency (DARPA)'])
        ->and($source->nameIn('ja'))->toBe('国防先端研究計画局（DARPA）')
        ->and($source->nameIn('en'))->toBe('Defense Advanced Research Projects Agency (DARPA)')
        // A language without its own name falls back to English.
        ->and($source->nameIn('de'))->toBe('Defense Advanced Research Projects Agency (DARPA)');
});

// The translator is given the source's names as a glossary for the target language, and nothing when none is set for it.
it('gives the translator the name chosen for the target language', function () {
    $material = Material::factory()->create();
    $material->document->source->update(['name' => '国防先端研究計画局（DARPA）', 'names' => ['en' => 'Defense Advanced Research Projects Agency (DARPA)']]);
    $article = Article::factory()->for($material)->create(['language' => 'en', 'headline' => 'A headline', 'body' => 'The lead.']);

    $toJapanese = collect(ProposeTranslation::request('Policy.', 'gpt-5.6-terra', $article, 'ja', [], 'Title', 'https://example.org')['input'])->pluck('content')->filter(fn ($content) => is_string($content))->implode("\n");
    $toFrench = collect(ProposeTranslation::request('Policy.', 'gpt-5.6-terra', $article, 'fr', [], 'Title', 'https://example.org')['input'])->pluck('content')->filter(fn ($content) => is_string($content))->implode("\n");

    expect($toJapanese)->toContain('Glossary: write the publisher of the primary source as "国防先端研究計画局（DARPA）" in 日本語')
        ->toContain('"Defense Advanced Research Projects Agency (DARPA)"')
        ->and($toFrench)->not->toContain('Glossary');
});

// The media shows the source in the language of the article.
it('shows the source by its name in the language of the article', function () {
    $this->travelTo('2026-09-25 03:00:00');
    $material = Material::factory()->create();
    $material->document->source->update(['name' => '国防先端研究計画局（DARPA）', 'names' => ['en' => 'Defense Advanced Research Projects Agency (DARPA)']]);
    $original = Article::factory()->for($material)->create(['language' => 'ja', 'headline' => '原文', 'body' => 'リード。']);
    Article::factory()->for($material)->create(['language' => 'en', 'translated_from_id' => $original->id, 'headline' => 'English', 'body' => 'Lead.']);

    $this->get('/media/ja')->assertSee('国防先端研究計画局（DARPA）');
    $this->get('/media/en')->assertSee('Defense Advanced Research Projects Agency (DARPA)')->assertDontSee('国防先端研究計画局');
});

// Adding a source queues its names in the other languages.
it('queues the names of a newly added source', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::editorial.sources.index')->set('name', '三菱電機')->set('url', 'https://www.mitsubishielectric.co.jp/news/')->call('add')->assertHasNoErrors();

    Queue::assertPushed(TranslateSourceNames::class, fn (TranslateSourceNames $job): bool => $job->source->name === '三菱電機');
});

// The names are asked for from the name in the UI language, for the languages still without one; a name a person set is kept.
it('names a source in the languages still without a name', function () {
    config(['services.openai.key' => 'test-key']);
    $source = Source::factory()->create(['name' => '国防先端研究計画局（DARPA）', 'names' => ['en' => 'Defense Advanced Research Projects Agency (DARPA)']]);
    $answer = ['zh-Hant' => '國防先進研究計劃署（DARPA）', 'de' => 'Defense Advanced Research Projects Agency (DARPA)', 'ko' => '국방첨단연구계획국(DARPA)', 'fr' => 'Agence pour les projets de recherche avancée de défense (DARPA)', 'zh-Hans' => '国防先进研究项目局（DARPA）', 'en' => 'Must not replace the English name'];
    Http::fake(['api.openai.com/v1/responses' => Http::response(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($answer)]]]]])]);

    (new TranslateSourceNames($source))->handle();

    expect($source->refresh()->names)->toMatchArray(['en' => 'Defense Advanced Research Projects Agency (DARPA)', 'ko' => '국방첨단연구계획국(DARPA)', 'zh-Hans' => '国防先进研究项目局（DARPA）'])
        ->and(TranslateSourceNames::missing($source))->toBe([]);
    Http::assertSent(fn (Request $request): bool => str_contains($request['input'][1]['content'], 'as written in 日本語: 国防先端研究計画局（DARPA）')
        && $request['text']['format']['schema']['required'] === ['zh-Hant', 'de', 'ko', 'fr', 'zh-Hans']);
});
