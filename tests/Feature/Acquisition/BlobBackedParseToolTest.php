<?php

use App\Acquisition\Agent\Tools\BlobBackedParseTool;
use App\Acquisition\Domain\Enums\BlobLayer;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Infrastructure\BlobStorage\BlobStore;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\Xml\ParseXmlTool;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('acquisition');
    $source = Source::factory()->create(['key' => 'example', 'base_url' => 'https://www.example.org/']);
    $this->context = ToolContext::forRun(AcquisitionRun::factory()->for($source)->create()->id);
});

// A sitemap of thousands of entries is unreadable for the Agent (seen on DARPA and NEDO);
// it gets the first 50 plus the total, while PHP callers keep the full parse.
it('cuts long lists in Agent-facing parse results and keeps the full count', function () {
    $urls = implode('', array_map(fn (int $i): string => "<url><loc>https://www.example.org/news/item-{$i}</loc></url>", range(1, 60)));
    $sitemap = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$urls.'</urlset>';
    $ref = app(BlobStore::class)->put(BlobLayer::Raw, $sitemap, 'application/xml');
    $tool = new BlobBackedParseTool(app(ParseXmlTool::class), 'xml', app(BlobStore::class));

    $result = $tool->run($tool->parseRequest(['raw_blob_uri' => $ref->uri, 'url' => 'https://www.example.org/sitemap.xml']), $this->context)->toArray();

    expect($result['kind'])->toBe('sitemap')
        ->and(count($result['entries']))->toBe(50)
        ->and($result['entries_total'])->toBe(60)
        ->and($result['entries'][0]['url'])->toBe('https://www.example.org/news/item-1')
        ->and($result)->not->toHaveKey('children_total');
});
