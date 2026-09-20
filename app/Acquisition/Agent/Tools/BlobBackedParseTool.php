<?php

namespace App\Acquisition\Agent\Tools;

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Infrastructure\BlobStorage\BlobNotFound;
use App\Acquisition\Infrastructure\BlobStorage\BlobStore;
use App\Acquisition\Tools\RequestValidation;
use App\Acquisition\Tools\Tool;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\ToolError;
use App\Acquisition\Tools\ToolRequest;
use App\Acquisition\Tools\ToolResult;

/**
 * Adapts a byte-oriented parse tool (parse_html / parse_xml / parse_pdf) for
 * the Agent: the payload names a RAW blob instead of carrying bytes, the
 * bytes are loaded here, and the inner tool runs unchanged.
 */
final class BlobBackedParseTool implements Tool
{
    /**
     * @param  string  $bytesKey  the inner request key that receives the bytes
     */
    public function __construct(
        private Tool $inner,
        private string $bytesKey,
        private BlobStore $blobs,
    ) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function parseRequest(array $payload): ToolRequest
    {
        $data = RequestValidation::validate($payload, [
            'raw_blob_uri' => ['required', 'string', 'starts_with:acquisition://raw/'],
        ]);

        try {
            $bytes = $this->blobs->get($data['raw_blob_uri']);
        } catch (BlobNotFound $exception) {
            throw new ToolError(ErrorCode::InvalidInput, 'Unknown RAW artifact.', ['raw_blob_uri' => $data['raw_blob_uri']], null, $exception);
        }

        unset($payload['raw_blob_uri']);

        return $this->inner->parseRequest([...$payload, $this->bytesKey => $bytes]);
    }

    public function run(ToolRequest $request, ToolContext $context): ToolResult
    {
        return $this->inner->run($request, $context);
    }
}
