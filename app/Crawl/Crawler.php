<?php

namespace App\Crawl;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The crawler's HTTP client and user agent (robots.txt is enforced by the
 * global HTTP middleware).
 */
class Crawler
{
    public const USER_AGENT = 'Genryu/0.2 (+https://genryu.test)';

    /** A request as the crawler, with a timeout in seconds. */
    public static function client(int $timeout = 20): PendingRequest
    {
        return Http::withUserAgent(self::USER_AGENT)->timeout($timeout);
    }

    /** GET a URL, throwing on an error status. */
    public static function get(string $url): Response
    {
        return self::client()->get($url)->throw();
    }
}
