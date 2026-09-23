<?php

use App\Actions\DrawImage;
use App\Actions\ProposeScene;
use App\Jobs\MakeImage;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\EditorialPolicy;
use App\Models\ImageStyle;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

const IMAGE_POLICY = "記事の未来の場面を1つ選ぶ。\n";

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Storage::fake('local');
    config(['services.openai.key' => 'test-key', 'services.openai.prices' => ['gpt-5.6-luna' => ['input' => 1, 'cached' => 0.1, 'output' => 2]]]);
    EditorialPolicy::query()->create(['layer' => 'image', 'body' => IMAGE_POLICY, 'model' => 'gpt-5.6-luna', 'image_model' => 'gpt-image-2.5-flare']);
    $this->actingAs(User::factory()->create());
});

/** A written Japanese original going out at 12:00 Tokyo time on 2026-09-24. */
function scheduledArticle(): Article
{
    return Article::factory()->create(['language' => 'ja', 'title' => '工業炉の炎はアンモニアでも燃える', 'scheduled_at' => '2026-09-24 03:00:00']);
}

function makeImage(Article $article): ArticleImage
{
    $image = MakeImage::queueFor($article);
    (new MakeImage($image))->handle(app(ProposeScene::class), app(DrawImage::class));

    return $image->refresh();
}

// The day is cut into bands; a time falls in the last band to have started, and before the first one in the band that ran past midnight.
it('finds the band of a time of day', function () {
    expect(ImageStyle::bandFor('07:00'))->toBe('before_work')
        ->and(ImageStyle::bandFor('09:00'))->toBe('before_lunch')
        ->and(ImageStyle::bandFor('12:30'))->toBe('lunch_break')
        ->and(ImageStyle::bandFor('13:00'))->toBe('afternoon')
        ->and(ImageStyle::bandFor('16:59'))->toBe('before_closing')
        ->and(ImageStyle::bandFor('18:00'))->toBe('after_work')
        ->and(ImageStyle::bandFor('23:00'))->toBe('late_night')
        ->and(ImageStyle::bandFor('03:00'))->toBe('late_night');
});

// The writer chooses the scene fitting the hour, the image model draws it in that hour's style, and the article carries the image and the time it was made for.
it('draws the top image of a scheduled article in the style of its hour', function () {
    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['scene' => 'A family eats lunch beside a quiet chemical plant.'])]]]],
            'usage' => ['input_tokens' => 2000, 'output_tokens' => 100],
        ]),
        'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode('JPEG')]], 'usage' => ['input_tokens' => 300, 'output_tokens' => 1500]]),
    ]);
    $article = scheduledArticle();
    $article->material->update(['data' => ['future_society' => ['千葉のコンビナートがアンモニアで動く'], 'figures' => [['url' => 'https://example.jp/fig1.jpg']]]]);

    $image = makeImage($article);

    expect($image->status)->toBe('made')
        ->and($image->time)->toBe('12:00')
        ->and($image->band)->toBe('lunch_break')
        ->and($image->scene)->toBe('A family eats lunch beside a quiet chemical plant.')
        ->and($image->image_prompt)->toContain('Picture-book illustration')->toContain(DrawImage::NEVER)
        ->and($image->estimated_total_cost)->toBeGreaterThan(0.0);
    Storage::disk('local')->assertExists($image->path);
    expect($article->refresh())->toMatchArray(['image_path' => $image->path, 'image_time' => '12:00'])
        ->and($article->publicationStatus())->toBe('scheduled');

    // The writer is told the hour's style and given what could change, never the source's figures.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/responses')
        && $request['input'][0]['content'][0]['text'] === IMAGE_POLICY
        && str_contains($request['input'][2]['content'], 'Picture-book illustration')
        && str_contains($request['input'][2]['content'], '千葉のコンビナート')
        && ! str_contains($request['input'][2]['content'], 'fig1.jpg'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/images/generations')
        && $request['model'] === 'gpt-image-2.5-flare' && $request['size'] === DrawImage::SIZE);

    // Moved to another hour, it needs another image.
    $article->update(['scheduled_at' => '2026-09-24 09:00:00']);
    expect($article->publicationStatus())->toBe('imaging');
});

it('fails without a scene, and draws only a scheduled original', function () {
    Http::fake(['api.openai.com/*' => Http::response(['output' => []])]);

    expect(makeImage(scheduledArticle())->status)->toBe('failed');

    $unscheduled = Article::factory()->create(['scheduled_at' => null]);
    expect(makeImage($unscheduled)->status)->toBe('failed')->and($unscheduled->refresh()->image_path)->toBeNull();
});

// The policy, both models and the styles of the bands live on the images screen; the button draws what has no image for its hour.
it('keeps the image settings on the images screen and queues the drawings', function () {
    $waiting = scheduledArticle();
    $drawn = scheduledArticle();
    $drawn->update(['image_path' => 'images/x.jpg', 'image_time' => '12:00']);

    Livewire::test('pages::production.images.index')
        ->set('imageModel', 'gpt-image-2.5-sunburst')
        ->set('bands.lunch_break.style', 'Watercolour, pastel.')
        ->call('savePolicy')->assertHasNoErrors()
        ->call('make');

    expect(EditorialPolicy::imageModel())->toBe('gpt-image-2.5-sunburst')
        ->and(ImageStyle::bands()['lunch_break']['style'])->toBe('Watercolour, pastel.');
    Queue::assertPushed(MakeImage::class, 1);
    expect($waiting->images()->sole()->band)->toBe('lunch_break');

    Livewire::test('pages::production.images.index')->set('bands.late_night.starts_at', '12:00')->call('savePolicy')->assertHasErrors(['bands.late_night.starts_at']);

    $this->get(route('production.images.index'))->assertSee('時間帯ごとの絵柄')->assertSee('お昼休み中')->assertSee('作成中');
});
