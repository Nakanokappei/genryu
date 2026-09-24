<?php

namespace App\Crawl;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * How the crawler identifies itself, and the plain GET every list reader
 * makes. robots.txt is enforced for every request by the global HTTP
 * middleware (AppServiceProvider); a forbidden URL throws
 * App\Exceptions\RobotsForbidden.
 */
class Crawler
{
    public const USER_AGENT = 'Genryu/0.2 (+https://genryu.test)';

    /** A request as the crawler, which gives up after the timeout in seconds. */
    public static function client(int $timeout = 20): PendingRequest
    {
        return Http::withUserAgent(self::USER_AGENT)->timeout($timeout);
    }

    /** The response at a URL, an error status thrown as an exception. */
    public static function get(string $url): Response
    {
        return self::client()->get($url)->throw();
    }
}
