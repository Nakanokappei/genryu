<?php

namespace App\Acquisition\Tools\Normalize;

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Tools\Html\ParsedHtml;
use App\Acquisition\Tools\Pdf\ParsedPdf;
use App\Acquisition\Tools\RequestValidation;
use App\Acquisition\Tools\ToolError;
use App\Acquisition\Tools\ToolRequest;
use App\Acquisition\Tools\Xml\ParsedXml;

/**
 * Input of normalize_document (plan §7.6): a parsed artifact plus its
 * source context.
 */
final readonly class NormalizeRequest implements ToolRequest
{
    public function __construct(
        public ParsedHtml|ParsedXml|ParsedPdf $parsed,
        public SourceContext $context,
        public int $normalizerVersion = 1,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $data = RequestValidation::validate($payload, [
            'parsed' => ['required', 'array'],
            'parsed.parser_id' => ['required', 'string'],
            'source_context' => ['required', 'array'],
            'normalizer_version' => ['sometimes', 'integer', 'in:1'],
        ]);

        // validated() keeps only keys that have rules, which would strip the
        // parsed artifact down to its parser_id; take the full arrays instead.
        /** @var array<string, mixed> $parsed */
        $parsed = $payload['parsed'];
        /** @var array<string, mixed> $context */
        $context = $payload['source_context'];
        $kind = explode('.', (string) $parsed['parser_id'], 2)[0];

        $artifact = match ($kind) {
            'html' => ParsedHtml::fromArray($parsed),
            'xml' => ParsedXml::fromArray($parsed),
            'pdf' => ParsedPdf::fromArray($parsed),
            default => throw new ToolError(ErrorCode::InvalidInput, "Cannot normalize output of parser {$parsed['parser_id']}.", ['parser_id' => $parsed['parser_id']]),
        };

        return new self($artifact, SourceContext::fromArray($context), (int) ($data['normalizer_version'] ?? 1));
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'parsed' => $this->parsed->toArray(),
            'source_context' => $this->context->toArray(),
            'normalizer_version' => $this->normalizerVersion,
        ];
    }
}
