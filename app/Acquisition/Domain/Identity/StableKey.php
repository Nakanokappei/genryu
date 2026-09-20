<?php

namespace App\Acquisition\Domain\Identity;

/**
 * A document's identity within its source, plus the rule that produced it
 * (stored as documents.identity_rule so the choice stays auditable).
 */
final readonly class StableKey
{
    public const RULE_FEED_GUID = 'feed_guid';

    public const RULE_CANONICAL = 'canonical';

    public const RULE_URL = 'url';

    public function __construct(
        public string $key,
        public string $rule,
    ) {}
}
