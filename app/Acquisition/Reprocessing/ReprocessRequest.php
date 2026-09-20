<?php

namespace App\Acquisition\Reprocessing;

use Carbon\CarbonImmutable;

/**
 * What to re-parse from stored RAW (plan §11). Maps one-to-one onto the
 * options of `acquisition:reprocess`.
 */
final readonly class ReprocessRequest
{
    public function __construct(
        public string $sourceKey,
        public string $parserId,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public ?string $documentStableKey = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->sourceKey,
            'parser' => $this->parserId,
            'from' => $this->from?->toIso8601ZuluString(),
            'to' => $this->to?->toIso8601ZuluString(),
            'document' => $this->documentStableKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceKey: (string) $data['source'],
            parserId: (string) $data['parser'],
            from: isset($data['from']) ? CarbonImmutable::parse((string) $data['from'])->utc() : null,
            to: isset($data['to']) ? CarbonImmutable::parse((string) $data['to'])->utc() : null,
            documentStableKey: isset($data['document']) ? (string) $data['document'] : null,
        );
    }
}
