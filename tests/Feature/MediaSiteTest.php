<?php

use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Prompt;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

// The site opens on the media, without signing in.
it('sends the root of the site to the media site', function () {
    $this->get('/')->assertRedirect('/media');
});

// The front page: the language versions to be published, written within the last 30 days, newest first; nothing older.
it('shows the articles of the last 30 days on the front page of a language', function () {
    $this->travelTo('2026-09-25 03:00:00');
    $newest = Article::factory()->create(['language' => 'ja', 'headline' => '新しい記事', 'body' => "新しい記事のリード。\n\n## 承\n\n本文。", 'scheduled_at' => '2026-09-24 03:00:00']);
    Article::factory()->create(['language' => 'ja', 'headline' => '少し前の記事', 'body' => 'リード。', 'created_at' => '2026-09-10 03:00:00']);
    Article::factory()->create(['language' => 'ja', 'headline' => '古すぎる記事', 'body' => 'リード。', 'created_at' => '2026-08-01 03:00:00']);
    Article::factory()->create(['language' => 'ja', 'headline' => '本文のない記事', 'body' => null]);
    Article::factory()->create(['language' => 'en', 'translated_from_id' => $newest->id, 'material_id' => $newest->material_id, 'headline' => 'An English article', 'body' => 'Lead.', 'scheduled_at' => '2026-09-24 16:00:00']);

    $this->get('/media')->assertOk()
        ->assertSee('Technology Watch')
        ->assertSeeInOrder(['新しい記事', '新しい記事のリード。', '少し前の記事'])
        ->assertDontSee('古すぎる記事')->assertDontSee('本文のない記事')->assertDontSee('An English article');
    $this->get('/media/en')->assertOk()->assertSee('An English article')->assertSee('From the laboratory to industry')
        // Today's date in the masthead, written the English way in New York.
        ->assertSee('September 24, 2026')->assertDontSee('2026年9月')
        // Genryu in the footer links to its repository.
        ->assertSee('href="https://github.com/Nakanokappei/genryu"', false);
    $this->get('/media/xx')->assertNotFound();
});

// An article page: the headline once (a translator's # line at the head of the body is not shown again), the body, and our top image.
it('shows an article with its top image and without its headline twice', function () {
    $this->travelTo('2026-09-25 03:00:00');
    Storage::disk('local')->put('images/1/1.jpg', 'jpeg bytes');
    $original = Article::factory()->create(['language' => 'ja', 'headline' => '原文', 'body' => 'リード。', 'image_path' => 'images/1/1.jpg']);
    $translation = Article::factory()->create(['language' => 'en', 'translated_from_id' => $original->id, 'material_id' => $original->material_id, 'headline' => 'The headline', 'body' => "# The headline\n\nThe lead."]);

    // The image URL carries the drawing, so a redrawn image is fetched again rather than taken from the browser's cache.
    $page = $this->get(route('media.article', ['en', $translation]))->assertOk()->assertSee('The lead.')->assertSee(route('media.image', ['article' => $translation, 'v' => '1']), false);
    expect(substr_count($page->getContent(), 'The headline'))->toBe(2); // the page title and the heading, not the body
    // A translation shows its original's top image, which the site serves.
    $this->get(route('media.image', $translation))->assertOk()->assertHeader('Cache-Control', 'max-age=86400, public');
    // Not in its language: not found.
    $this->get(route('media.article', ['ja', $translation]))->assertNotFound();
});

// A top image is served only for an article the site shows: not for one without a body, not published in its language, or older than the window.
it('does not serve the top image of an article the site does not show', function () {
    $this->travelTo('2026-09-25 03:00:00');
    Storage::disk('local')->put('images/1/1.jpg', 'jpeg bytes');
    $shown = Article::factory()->create(['language' => 'ja', 'body' => 'リード。', 'image_path' => 'images/1/1.jpg']);
    $unwritten = Article::factory()->create(['language' => 'ja', 'body' => null, 'image_path' => 'images/1/1.jpg']);
    $tooOld = Article::factory()->create(['language' => 'ja', 'body' => 'リード。', 'image_path' => 'images/1/1.jpg', 'created_at' => '2026-08-01 03:00:00']);
    // German takes only German sources until 言語設定 says otherwise, so a German translation of a Japanese article is not published.
    $unpublished = Article::factory()->create(['language' => 'de', 'translated_from_id' => $shown->id, 'material_id' => $shown->material_id, 'body' => 'Der Vorspann.']);

    $this->get(route('media.image', $shown))->assertOk();
    $this->get(route('media.image', $unwritten))->assertNotFound();
    $this->get(route('media.image', $tooOld))->assertNotFound();
    $this->get(route('media.image', $unpublished))->assertNotFound();
});

// The top images are ours and take room: the ones drawn more than 30 days ago are deleted, and the articles no longer point at them.
it('deletes the top images drawn more than 30 days ago', function () {
    $this->travelTo('2026-09-25 03:00:00');
    $article = Article::factory()->create(['image_path' => 'images/1/old.jpg']);
    Storage::disk('local')->put('images/1/old.jpg', 'old');
    Storage::disk('local')->put('images/1/new.jpg', 'new');
    $drawing = ['article_id' => $article->id, 'prompt_id' => Prompt::current('image', 'Choose a scene.')->id, 'scene_model' => 'gpt-5.6-luna', 'image_model' => 'gpt-image-2.5-flare', 'time' => '12:00', 'band' => 'lunch_break', 'status' => 'made'];
    $old = ArticleImage::query()->create([...$drawing, 'path' => 'images/1/old.jpg']);
    $old->forceFill(['created_at' => '2026-08-20 00:00:00'])->save();
    ArticleImage::query()->create([...$drawing, 'path' => 'images/1/new.jpg']);

    $this->artisan('media:prune-images')->expectsOutputToContain('1 top images')->assertSuccessful();

    Storage::disk('local')->assertMissing('images/1/old.jpg');
    Storage::disk('local')->assertExists('images/1/new.jpg');
    expect($old->refresh()->path)->toBeNull()->and($article->refresh()->image_path)->toBeNull();
});

// An article scheduled for later is not on the site yet — not listed, no page, no image; it is checked on the admin screens.
it('does not show an article scheduled for later', function () {
    $this->travelTo('2026-09-25 03:00:00');
    Storage::disk('local')->put('images/1/1.jpg', 'jpeg bytes');
    $published = Article::factory()->create(['language' => 'ja', 'headline' => '公開済みの記事', 'body' => 'リード。', 'scheduled_at' => '2026-09-24 23:30:00', 'published_at' => '2026-09-24 23:30:00']);
    $upcoming = Article::factory()->create(['language' => 'ja', 'headline' => '公開予定の記事', 'body' => 'リード。', 'scheduled_at' => '2026-09-28 00:30:00', 'image_path' => 'images/1/1.jpg']);

    $this->get('/media')->assertOk()->assertSee('公開済みの記事')->assertDontSee('公開予定の記事')->assertDontSee('公開予定');
    $this->get(route('media.article', ['ja', $upcoming]))->assertNotFound();
    $this->get(route('media.image', $upcoming))->assertNotFound();
    $this->get(route('media.article', ['ja', $published]))->assertOk()->assertSee('2026年9月25日');
});
