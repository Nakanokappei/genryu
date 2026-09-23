<?php

use App\Models\Article;
use App\Models\Prompt;
use App\Models\User;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

/** A checked original, with what its way out has reached. */
function productionArticle(string $title, array $attributes = []): Article
{
    $article = Article::factory()->create(['language' => 'ja', 'title' => $title, ...$attributes]);
    $article->qualityChecks()->create(['prompt_id' => Prompt::current('quality', 'rubric')->id, 'model' => 'gpt-5.6-luna', 'status' => 'checked', 'score' => 80]);

    return $article;
}

// An article moves 公開日時未定 → 画像作成中 → スケジュール済み → 公開済み, worked out from what it holds.
it('lists the articles on their way out and those already out, each with where it stands', function () {
    $waiting = productionArticle('時刻を待つ記事');
    $imaging = productionArticle('画像を待つ記事', ['scheduled_at' => '2026-09-24 00:00:00']);
    $scheduled = productionArticle('準備のできた記事', ['scheduled_at' => '2026-09-24 03:00:00', 'image_path' => 'images/1.png', 'image_time' => '12:00']);
    $published = productionArticle('公開した記事', ['scheduled_at' => '2026-09-22 03:00:00', 'image_path' => 'images/2.png', 'published_at' => '2026-09-22 03:00:00']);

    expect([$waiting->publicationStatus(), $imaging->publicationStatus(), $scheduled->publicationStatus(), $published->publicationStatus()])
        ->toBe(['unscheduled', 'imaging', 'scheduled', 'published']);

    $this->get(route('production.articles.index'))
        ->assertSeeInOrder(['公開予定', '画像を待つ記事', '準備のできた記事', '時刻を待つ記事', '公開済み', '公開した記事'])
        ->assertSee('公開日時未定')->assertSee('画像作成中')->assertSee('スケジュール済み');
});
