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
    $newest = Article::factory()->create(['language' => 'ja', 'title' => '新しい記事', 'body' => "新しい記事のリード。\n\n## 承\n\n本文。", 'scheduled_at' => '2026-09-24 03:00:00']);
    Article::factory()->create(['language' => 'ja', 'title' => '少し前の記事', 'body' => 'リード。', 'created_at' => '2026-09-10 03:00:00']);
    Article::factory()->create(['language' => 'ja', 'title' => '古すぎる記事', 'body' => 'リード。', 'created_at' => '2026-08-01 03:00:00']);
    Article::factory()->create(['language' => 'ja', 'title' => '本文のない記事', 'body' => null]);
    Article::factory()->create(['language' => 'en', 'translated_from_id' => $newest->id, 'material_id' => $newest->material_id, 'title' => 'An English article', 'body' => 'Lead.', 'scheduled_at' => '2026-09-24 16:00:00']);

    $this->get('/media')->assertOk()
        ->assertSee('Technology Watch')
        ->assertSeeInOrder(['新しい記事', '新しい記事のリード。', '少し前の記事'])
        ->assertDontSee('古すぎる記事')->assertDontSee('本文のない記事')->assertDontSee('An English article');
    $this->get('/media/en')->assertOk()->assertSee('An English article')->assertSee('From the laboratory to industry');
    $this->get('/media/xx')->assertNotFound();
});

// An article page: the headline once (a translator's # line at the head of the body is not shown again), the body, and our top image.
it('shows an article with its top image and without its headline twice', function () {
    $this->travelTo('2026-09-25 03:00:00');
    Storage::disk('local')->put('images/1/1.jpg', 'jpeg bytes');
    $original = Article::factory()->create(['language' => 'ja', 'title' => '原文', 'body' => 'リード。', 'image_path' => 'images/1/1.jpg']);
    $translation = Article::factory()->create(['language' => 'en', 'translated_from_id' => $original->id, 'material_id' => $original->material_id, 'title' => 'The headline', 'body' => "# The headline\n\nThe lead."]);

    $page = $this->get(route('media.article', ['en', $translation]))->assertOk()->assertSee('The lead.')->assertSee(route('media.image', $translation));
    expect(substr_count($page->getContent(), 'The headline'))->toBe(2); // the page title and the heading, not the body
    // A translation shows its original's top image, which the site serves.
    $this->get(route('media.image', $translation))->assertOk()->assertHeader('Cache-Control', 'max-age=86400, public');
    // Not in its language: not found.
    $this->get(route('media.article', ['ja', $translation]))->assertNotFound();
});

// The top images are ours and take room: the ones drawn more than 30 days ago are deleted, and the articles no longer point at them.
it('deletes the top images drawn more than 30 days ago', function () {
    $this->travelTo('2026-09-25 03:00:00');
    $article = Article::factory()->create(['image_path' => 'images/1/old.jpg']);
    Storage::disk('local')->put('images/1/old.jpg', 'old');
    Storage::disk('local')->put('images/1/new.jpg', 'new');
    $drawing = ['article_id' => $article->id, 'prompt_id' => Prompt::current('image', 'Choose a scene.')->id, 'model' => 'gpt-5.6-luna', 'image_model' => 'gpt-image-2.5-flare', 'time' => '12:00', 'band' => 'lunch_break', 'status' => 'made'];
    $old = ArticleImage::query()->create([...$drawing, 'path' => 'images/1/old.jpg']);
    $old->forceFill(['created_at' => '2026-08-20 00:00:00'])->save();
    ArticleImage::query()->create([...$drawing, 'path' => 'images/1/new.jpg']);

    $this->artisan('media:prune-images')->expectsOutputToContain('1 top images')->assertSuccessful();

    Storage::disk('local')->assertMissing('images/1/old.jpg');
    Storage::disk('local')->assertExists('images/1/new.jpg');
    expect($old->refresh()->path)->toBeNull()->and($article->refresh()->image_path)->toBeNull();
});
