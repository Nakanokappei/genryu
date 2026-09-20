<?php

namespace App\Providers;

use App\Acquisition\Infrastructure\BlobStorage\BlobStore;
use App\Acquisition\Infrastructure\BlobStorage\FilesystemBlobStore;
use App\Acquisition\Tools\Html\ParseHtmlTool;
use App\Acquisition\Tools\Http\FetchUrlTool;
use App\Acquisition\Tools\Normalize\NormalizeDocumentTool;
use App\Acquisition\Tools\Pdf\ParsePdfTool;
use App\Acquisition\Tools\ToolRegistry;
use App\Acquisition\Tools\Xml\ParseXmlTool;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the acquisition platform's ports to their infrastructure adapters.
 */
class AcquisitionServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The disk is resolved lazily so Storage::fake('acquisition') in
        // tests takes effect before the store is first used.
        $this->app->bind(BlobStore::class, function ($app): BlobStore {
            return new FilesystemBlobStore($app->make(FilesystemFactory::class)->disk('acquisition'));
        });

        // The complete allowlist of Tools (AT-14). Adding a Tool means adding
        // it here and nowhere else.
        $this->app->singleton(ToolRegistry::class, function ($app): ToolRegistry {
            return new ToolRegistry([
                $app->make(FetchUrlTool::class),
                $app->make(ParseHtmlTool::class),
                $app->make(ParseXmlTool::class),
                $app->make(ParsePdfTool::class),
                $app->make(NormalizeDocumentTool::class),
            ]);
        });
    }
}
