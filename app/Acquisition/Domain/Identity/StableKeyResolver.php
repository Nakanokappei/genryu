<?php

namespace App\Acquisition\Domain\Identity;

use InvalidArgumentException;

/**
 * Derives a document's stable_key following the priority order in ADR-0003:
 * feed GUID / official ID, then canonical URL, then the final fetched URL.
 */
final class StableKeyResolver
{
    /**
     * @param  string|null  $feedGuid  GUID / Atom id / official identifier, if the entrypoint provided one
     * @param  string|null  $canonicalUrl  canonical link or og:url found in the document
     * @param  string  $finalUrl  the URL the bytes were actually served from (after redirects)
     * @param  list<string>  $extraStripParameters  profile-level query parameters to ignore
     */
    public static function resolve(?string $feedGuid, ?string $canonicalUrl, string $finalUrl, array $extraStripParameters = []): StableKey
    {
        $guid = trim((string) $feedGuid);

        if ($guid !== '') {
            return new StableKey('guid:'.$guid, StableKey::RULE_FEED_GUID);
        }

        $canonical = trim((string) $canonicalUrl);

        if ($canonical !== '') {
            try {
                return new StableKey('url:'.UrlNormalizer::normalize($canonical, $extraStripParameters), StableKey::RULE_CANONICAL);
            } catch (InvalidArgumentException) {
                // A relative or malformed canonical is not an identity; fall through to the URL rule.
            }
        }

        return new StableKey('url:'.UrlNormalizer::normalize($finalUrl, $extraStripParameters), StableKey::RULE_URL);
    }
}
