<?php

namespace App\Providers;

use App\Acquisition\Agent\AcquisitionOrchestrator;
use App\Acquisition\Agent\AgentToolBridge;
use App\Acquisition\Agent\Tools\AgentFetchTool;
use App\Acquisition\Agent\Tools\AgentNormalizeTool;
use App\Acquisition\Agent\Tools\BlobBackedParseTool;
use App\Acquisition\Agent\Tools\StoreProfileCandidateTool;
use App\Acquisition\Infrastructure\AgentSdk\ClaudeAgentSdkOrchestrator;
use App\Acquisition\Infrastructure\BlobStorage\BlobStore;
use App\Acquisition\Infrastructure\BlobStorage\FilesystemBlobStore;
use App\Acquisition\Tools\Discovery\DiscoverWebTool;
use App\Acquisition\Tools\Html\ParseHtmlTool;
use App\Acquisition\Tools\Http\FetchUrlTool;
use App\Acquisition\Tools\Http\RobotsPolicy;
use App\Acquisition\Tools\Normalize\NormalizeDocumentTool;
use App\Acquisition\Tools\Pdf\ParsePdfTool;
use App\Acquisition\Tools\ToolDispatcher;
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

        // The Agent-facing tool set behind the CLI bridge (ADR-0001, AT-14).
        // Byte-oriented parsers are wrapped so the Agent passes RAW blob
        // references, never bodies. Names must match config acquisition.agent_tools.
        $this->app->singleton(AgentToolBridge::class, function ($app): AgentToolBridge {
            $blobs = $app->make(BlobStore::class);

            return new AgentToolBridge(new ToolDispatcher(new ToolRegistry([
                $app->make(DiscoverWebTool::class),
                $app->make(AgentFetchTool::class),
                new BlobBackedParseTool($app->make(ParseHtmlTool::class), 'html', $blobs),
                new BlobBackedParseTool($app->make(ParseXmlTool::class), 'xml', $blobs),
                new BlobBackedParseTool($app->make(ParsePdfTool::class), 'pdf', $blobs),
                $app->make(AgentNormalizeTool::class),
                $app->make(StoreProfileCandidateTool::class),
            ])));
        });

        $this->app->bind(AcquisitionOrchestrator::class, ClaudeAgentSdkOrchestrator::class);

        // One robots.txt cache per request/job, shared by every fetcher in it.
        $this->app->scoped(RobotsPolicy::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Acquisition runs and the Agent's tool calls are console processes
        // that hold hundreds of RAW bodies and PDF parses; PHP's default
        // 128M killed NEDO run #16 (see config acquisition.memory_limit).
        if ($this->app->runningInConsole()) {
            ini_set('memory_limit', (string) config('acquisition.memory_limit'));
        }
    }
}
