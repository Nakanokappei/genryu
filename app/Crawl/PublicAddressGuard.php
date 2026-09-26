<?php

namespace App\Crawl;

use App\Exceptions\PrivateAddressForbidden;
use App\Exceptions\ResponseTooLarge;
use Closure;
use Psr\Http\Message\RequestInterface;

/**
 * Global Guzzle middleware for every outgoing request (and every redirect hop):
 * http(s) only, to public addresses only, with the address checked pinned for
 * the connection and the response size capped.
 */
class PublicAddressGuard
{
    /** The largest response body accepted, in bytes. */
    public const MAX_RESPONSE_BYTES = 50 * 1024 * 1024;

    /** Wrap the next handler. */
    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            $uri = $request->getUri();
            $host = strtolower(trim($uri->getHost(), '[]'));

            // Only the web's own schemes.
            if (! in_array(strtolower($uri->getScheme()), ['http', 'https'], true)) {
                throw new PrivateAddressForbidden(__('Only http and https URLs are fetched: :url', ['url' => (string) $uri]));
            }

            $addresses = self::addressesOf($host);

            // Refuse loopback, private, link-local (cloud metadata) and reserved addresses.
            foreach ($addresses as $address) {
                if (! self::isPublic($address)) {
                    throw new PrivateAddressForbidden(__('Not a public address: :url', ['url' => (string) $uri]));
                }
            }

            // Stop a response that grows past the cap.
            $options['progress'] = function (int $expected, int $downloaded) use ($uri): void {
                if ($downloaded > self::MAX_RESPONSE_BYTES) {
                    throw new ResponseTooLarge(__('The response is larger than :size MB: :url', ['size' => self::MAX_RESPONSE_BYTES / 1024 / 1024, 'url' => (string) $uri]));
                }
            };

            // Connect to the addresses just checked, so a second lookup cannot answer differently.
            if ($addresses !== [] && filter_var($host, FILTER_VALIDATE_IP) === false) {
                $port = $uri->getPort() ?? (strtolower($uri->getScheme()) === 'https' ? 443 : 80);
                $options['curl'] = [CURLOPT_RESOLVE => [$host.':'.$port.':'.implode(',', $addresses)]] + (array) ($options['curl'] ?? []);
                $options['force_ip_resolve'] = 'v4';
            }

            return $handler($request, $options);
        };
    }

    /**
     * The addresses a host stands for: itself when it is one, else its IPv4 addresses
     * (not looked up under tests, which never touch the network).
     *
     * @return list<string>
     */
    private static function addressesOf(string $host): array
    {
        // A literal address, or a name that is always local.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return ['127.0.0.1'];
        }

        if (app()->runningUnitTests()) {
            return [];
        }

        $addresses = gethostbynamel($host);

        // Unresolvable: refused here rather than left to a resolver that may answer later.
        if ($addresses === false || $addresses === []) {
            throw new PrivateAddressForbidden(__('The host could not be resolved: :host', ['host' => $host]));
        }

        return $addresses;
    }

    /** Whether an address is public (not private, loopback, link-local or reserved). */
    private static function isPublic(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
